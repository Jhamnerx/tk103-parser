<?php


error_reporting(E_ERROR | E_PARSE);

if (isset($_SERVER["REMOTE_ADDR"])) {
    die("Can not run through web server.");
}

// gps datetime info will be in GMT, so we need to know the difference
$gmtdiff = date('Z'); // timezone difference in seconds. positive for the East, negative for the west of GMT

// setup socket server
$socket = stream_socket_server('0.0.0.0:2030', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

if (!$socket) {
    die("\n" . date("H:i:s") . " Test open stream: $errstr\n");
}

// we are ready to receive connections
logdata('BOOT', (int) $socket, '0');
echo "\n" . date("H:i:s") . " (#" . (int) $socket . ") Waiting for connection..";

// the $clients array contains both the server socket (listen to new connections) and the clients
// each entry contains all meta data
$clients = array(
    (int) $socket => array(
        'sock' => $socket, // socket itself
        'ip' => "", // ip address of sender (0 for server)
        'serial' => 0, // unique serial number sender (0 for server)
        'time' => time() // timestamp of last communication
    )
);

while (true) {
    echo (count($clients) - 1); // show once every 60 seconds how many connections we have

    $gmtdiff = date('Z'); // need to recalc timezone difference in seconds if this script runs throughout a daylight savings change throughout the year

    // first we look if we have any duplicate entries (same serial number). This means the client reconnected
    // without clearly shutting down the connection
    foreach ($clients as $c1) {
        if ($c1['serial'] != 0) { // exclude the server or connections that just have opened
            foreach ($clients as $c2) {
                // when another entry (differnt socket number) has the same serial number, we kill the oldest one
                if ((int) $c1['sock'] != (int) $c2['sock'] && $c1['serial'] == $c2['serial'] && $c1['time'] < $c2['time']) {
                    $i = (int) $c1['sock'];
                    echo "\n" . date("H:i:s") . " (#$i) Clean up socket from " . $c1['ip'];
                    logdata('DOWN', $i, $c1['ip']);
                    // print_r($clients);
                    stream_socket_shutdown($c1['sock'], STREAM_SHUT_RDWR);
                    unset($clients[$i]);
                }
            }
        } elseif ((int) $c1['sock'] != (int) $socket && $c1['time'] < time() - 600) {
            $i = (int) $c1['sock'];
            echo "\n" . date("H:i:s") . " (#$i) Clean up orphen socket";
            logdata('KILL', $i, $c1['ip']);
            stream_socket_shutdown($c1['sock'], STREAM_SHUT_RDWR);
            unset($clients[$i]);
        }
    }

    // Compile a list of all our streams to call stream_select
    $read = array_column($clients, 'sock');
    $write = null;
    $ex = null;
    // stream_select will either end after 60 seconds or let us know if anything happens (new or existing socket)
    // if something happens, $read will be updated with those sockets that have data
    stream_select($read, $write, $ex, 60);

    foreach ($read as $sock) {
        if ($sock === $socket) {
            // something happens on the main server socket - a new connection
            $client = stream_socket_accept($socket, 1, $peername);
            echo "\n" . date("H:i:s") . " (#" . (int) $client . ") New connection ";
            if ($client) {
                echo "from $peername";
                logdata('CONN', (int) $client, $peername);
                stream_set_timeout($client, 1);
                stream_set_blocking($client, false);
                $clients[(int) $client] = array(
                    'sock' => $client,
                    'ip' => $peername,
                    'serial' => 0,  // will be populated when we start receiving data
                    'time' => time() // will be updated when we receive data
                );
            } else {
                echo "... aborted.";
            }
        } else {
            // We may receive multiple entries (... data ...)(... data ...). Split based on closing ')'
            $ana = explode(')', stream_get_contents($clients[(int) $sock]['sock']));

            foreach ($ana as $hst) {
                // example: "(057045206556BP05357857045206556190503A5210.8942N00428.4043E000.0134955000.0000000000L00000000)";
                // note: the closing bracket ')' is stripped off by explode()

                // first analyze command structure
                if (preg_match('/\((\d{12})([AB][OPQRSTUVXY]\d\d)(.+)/', $hst, $command)) {
                    /* this returns Array (
                            [0] => (057045206556BP05357857045206556190503A5210.8942N00428.4043E000.0134955000.0000000000L00000000)
                            [1] => 057045206556   --> serial number (12 bytes)
                            [2] => BP05 -> command code
                            [3] => 357857045206556190503A5210.8942N00428.4043E000.0134955000.0000000000L00000000 --> data
                        )
                    */
                    //echo "\n" . date("H:i:s") . " (#" . (int) $sock . ") SN=" . $command[1] . " Cmd=" . $command[2] . " data=" . $command[3] . " ";

                    $clients[(int) $sock]['serial'] = $command[1];
                    $clients[(int) $sock]['time'] = time();

                    // now analyze the gps data
                    $havegps = false;
                    if (preg_match('/(.*)(\d{6})A([\d\.]+)([NS])([\d\.]+)([EW])([\d\.]{5})(\d{6})([\d\.]{6})(\d+)L(\d+)/', $command[3], $match)) {
                        // print_r($match);
                        /* returns analysis of GPS data
                            [0] => 357857045206556190503A5210.8942N00428.4043E000.0134955000.0000000000L00000000
                            [1] => 357857045206556 --> IMEI or for other commands this may have other data or may be empty
                            [2] => 190503 -> DATE YYMMDD
                            [3] => 5210.8942 -> North
                            [4] => N
                            [5] => 00428.4043 -> East
                            [6] => E
                            [7] => 000.0 -> speed
                            [8] => 134955 -> time HHMMSS
                            [9] => 000.00 -> angle in degrees
                            [10] => 00000000 -> status bits
                            [11] => 00000000 -> distance total in meter
                        */
                        print_r('command:' . $command);
                        print_r('match:' . $match);
                        $havegps = true;

                        // store in database
                        // please note we store two different times for each record:
                        // datetime - date/time we received this data
                        // gpstime - time when data was sent by the gps tracker. Some trackers may delay sending
                        //    data points or even may reverse order of sending (an error condition may only be sent one 1 minute later)
                        // you should always use gpsdata to find the position at a given time. gpsdata is received in UTC time zone
                        // and is converted in your local time zone
                        $hst = substr_replace(substr_replace(
                            $match[8],
                            ':',
                            4,
                            0
                        ), ':', 2, 0); // format HH:mm:ss

                        $datarec = [
                            'datetime' => date("Y-m-d H:i:s"),
                            'gpstime' => date("H:i:s", strtotime(formatTime($match[8])) + $gmtdiff), // convert gmt to our time
                            'gpsdate' => formatDate($match[2]),
                            'socket' => (int) $sock,
                            'serial' => $command[1],
                            'valid' => 1,
                            'cmd' => $command[2],
                            'err' => ($match[1] > 9 ? 0 : $match[1]),
                            'lat' => gpsDDM2DD($match[3] . $match[4]),
                            'long' => gpsDDM2DD($match[5] . $match[6]),
                            'cordenadas' => convertirCoordenadas($match[3] . $match[4], $match[5] . $match[6]),
                            'heading' => $match[9] * 1,
                            'speed' => $match[7] * 1,
                            'status' => $match[10] * 1,
                            'distance' => $match[11] * 1,
                            'ip' => $clients[(int) $sock]['ip'],
                            //'gpstime' => date("H:i:s", strtotime(formatTime($match[8])) + $gmtdiff), // convert gmt to our time
                            //'gpstime' => date("H:i:s", strtotime($hst) + $gmtdiff) // convert gmt to our time
                        ];

                        var_dump($datarec);
                    }

                    // now analyze command from client and see if we need to respond
                    switch ($command[2]) {
                        case 'BP05': // login, may includes gps if there is gps fix, should response with AP05
                            echo "Login ";
                            sendcmd($sock, $command[1], 'AP05', '');
                            break;
                        case 'BP00': // handshake. data is like: 357857045206556HSO1a4
                            echo "Handshake ";
                            sendcmd($sock, $command[1], 'AP01', 'HSO'); // will be sent a few times after login completed
                            break;
                        case 'BO01': // alarm message, includes gps
                            // alarm codes
                            // 0:power off
                            // 1:accident
                            // 2:robbery
                            // 3:anti theft
                            // 4:lowspeed
                            // 5:overspeed
                            // 6:geofence
                            // 7:shock alarm
                            $alm = ($havegps ? $match[1] : '0');
                            echo "Alarm=$alm ";
                            sendcmd($sock, $command[1], 'AS01', $alm);
                            break;
                        case 'BO02': // Alarm for data offset and messages return, includes gps, code in $match[1]
                            $alm = ($havegps ? $match[1] : '0');
                            echo "Alarm=$alm ";
                            // alarmcodes: (no need to respond)
                            // 0:Cut of vehicle oil
                            // 1:vehicle anti-theft alarm
                            // 2:Vehiclerob (SOShelp)
                            // 3:Happen accident
                            // 4:Vehiclelow speed alarm
                            // 5:Vehicleover speed alarm
                            // 6:Vehicleout of Geo-fence
                            break;
                        case 'BP01': // response of sw version number, no response
                        case 'BP04': // answered calling message, includes gps data, no response
                        case 'BR00': // isochronous feedback, includes GPS, no response - when moving or standing still?
                        case 'BR01': // isometry continuous feedback GPS, no response - when standing still once every 10 min
                        case 'BR02': // continous ending message, include GPS, no repsonse
                            break;

                        default:
                            echo "Unknown command=" . $command[2] . " ";
                    }
                } else {
                    if (strlen($hst) > 1) {
                        echo "\n" . date("H:i:s") . " (#" . (int) $sock . ") Not recognized=" . $hst . " ";
                    }
                }
            }
            // if the client closes the socket, we will also free up memory
            if (feof($sock)) {
                $i = (int) $sock;
                echo "\n" . date("H:i:s") . " (#$i) Remote closed connection";
                logdata('CLOS', $i, $clients[$i]['ip']);
                stream_socket_shutdown($sock, STREAM_SHUT_RDWR);
                unset($clients[$i]);
            }
        }
    }
}

// program will never come here
fclose($socket);

// ----------------------------------------------------------------------

function sendcmd($sock, $serial, $cmd, $arg)
{
    // return data to the device
    fwrite($sock, "(" . $serial . $cmd . $arg . ")\n");
    echo "Send $cmd $arg ";
    fflush($sock);
}

function gpsDDM2DD(string $gps)
{
    if (strlen($gps) == 10) {
        // Dividir la latitud y la longitud en grados y minutos
        $gradosLat = substr($gps, 0, 2);
        $minutosLat = substr($gps, 2, 7);

        // Convertir a grados decimales
        $latDecimal = $gradosLat + ($minutosLat / 60);

        // Ajustar el signo según el hemisferio
        $latDecimal = (strpos($gps, 'S') !== false) ? -$latDecimal : $latDecimal;

        return $latDecimal;
    }

    if (strlen($gps) == 11) {
        // Dividir la latitud y la longitud en grados y minutos
        $gradosLon = substr($gps, 0, 3);
        $minutosLon = substr($gps, 3, 7);

        // Convertir a grados decimales
        $lonDecimal = $gradosLon + ($minutosLon / 60);

        // Ajustar el signo según el hemisferio
        $lonDecimal = (strpos($gps, 'W') !== false) ? -$lonDecimal : $lonDecimal;

        return $lonDecimal;
    }
}
function convertirCoordenadas($latitud, $longitud)
{
    // Dividir la latitud y la longitud en grados y minutos
    $gradosLat = substr($latitud, 0, 2);
    $minutosLat = substr($latitud, 2, 7);
    $gradosLon = substr($longitud, 0, 3);
    $minutosLon = substr($longitud, 3, 7);

    // Convertir a grados decimales
    $latDecimal = $gradosLat + ($minutosLat / 60);
    $lonDecimal = $gradosLon + ($minutosLon / 60);

    // Ajustar el signo según el hemisferio
    $latDecimal = (strpos($latitud, 'S') !== false) ? -$latDecimal : $latDecimal;
    $lonDecimal = (strpos($longitud, 'W') !== false) ? -$lonDecimal : $lonDecimal;

    // Retornar las coordenadas convertidas
    return array('latitud' => $latDecimal, 'longitud' => $lonDecimal);
}

function formatTime($time)
{
    return substr_replace(substr_replace($time, ':', 4, 0), ':', 2, 0);
}
function formatDate($fecha)
{
    // Crear un objeto DateTime desde el formato específico
    $fechaObj = DateTime::createFromFormat('ymd', $fecha);

    // Formatear la fecha al formato Y-m-d
    return $fechaObj->format('Y-m-d');
}

function logdata($cmd, $socket, $ip)
{
    // log a simple code in the database without gps data
    $datarec = [
        'datetime' => date("Y-m-d H:i:s"),
        'socket' => $socket,
        'valid' => 0,
        'serial' => 0,
        'cmd' => $cmd,
        'err' => 0,
        'lat' => 0,
        'long' => 0,
        'heading' => 0,
        'speed' => 0,
        'status' => 0,
        'distance' => 0,
        'ip' => $ip,
        'gpstime' => date("H:i:s")
    ];
}
