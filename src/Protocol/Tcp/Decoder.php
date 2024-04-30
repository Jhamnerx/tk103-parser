<?php

declare(strict_types=1);

namespace Jhamner\Tk103Parser\Protocol\Tcp;

use DateTime;
use Jhamner\Tk103Parser\Model\Imei;
use Jhamner\Tk103Parser\Codec\Codec8;
use Jhamner\Tk103Parser\Codec\BaseCodec;
use Jhamner\Tk103Parser\DecoderInterface;



class Decoder implements DecoderInterface
{

    public function decodeData(string $payload): array
    {
        // first analyze command structure
        if (preg_match('/\((\d{12})([AB][OPQRSTUVXY]\d\d)(.+)/', $payload, $command)) {

            // now analyze the gps data
            $havegps = false;
            $dataGps = [];

            if (preg_match('/(.*)(\d{6})A([\d\.]+)([NS])([\d\.]+)([EW])([\d\.]{5})(\d{6})([\d\.]{6})(\d+)L(\d+)/', $command[3], $match)) {

                $havegps = true;
                $gmtdiff = date('Z'); // get the timezone offset in seconds
                $dataGps = [
                    'imei' => $this->decodeImei($payload),
                    'gps_time' => date("H:i:s", strtotime($this->formatTime($match[8])) + $gmtdiff), // convert gmt to our time
                    'gps_date' => $this->formatDate($match[2]),
                    'datetime_gps' => $this->formatDate($match[2]) . " " . date("H:i:s", strtotime($this->formatTime($match[8])) + $gmtdiff),
                    'datetime' => date("Y-m-d H:i:s"),
                    'serial' => $command[1],
                    'valid' => 1,
                    'cmd' => $command[2],
                    'err' => ($match[1] > 9 ? 0 : $match[1]),
                    'lat' => $this->gpsDDM2DD($match[3] . $match[4]),
                    'long' => $this->gpsDDM2DD($match[5] . $match[6]),
                    'cordenadas' => $this->convertirCoordenadas($match[3] . $match[4], $match[5] . $match[6]),
                    'angle' => $match[9] * 1,
                    'speed' => $match[7] * 1,
                    'status' => $match[10] * 1,
                    'distance' => $match[11] * 1,
                    // 'ip' => $clients[(int) $sock]['ip'],
                    //'gpstime' => date("H:i:s", strtotime($hst) + $gmtdiff)
                ];
            }

            $commandout = '';
            // now analyze command from client and see if we need to respond
            switch ($command[2]) {
                case 'BP05': // login, may includes gps if there is gps fix, should response with AP05
                    echo "Login ";
                    $commandout = $this->getCommand($command[1], 'AP05', '');
                    break;
                case 'BP00': // handshake. data is like: 357857045206556HSO1a4
                    echo "Handshake ";
                    $commandout = $this->getCommand($command[1], 'AP01', 'HSO'); // will be sent a few times after login completed
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
                    $commandout = $this->getCommand($command[1], 'AS01', $alm);
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

            return [
                'have_gps' => $havegps,
                'data_gps' => $dataGps,
                'command' => $commandout,
            ];
        }
    }

    public function decodeImei(string $payload): Imei
    {
        if (preg_match('/\((\d{12})([AB][OPQRSTUVXY]\d\d)(.+)/', $payload, $command)) {

            return new Imei($command[1]);
        }
    }

    public function getCommand($serial, $cmd, $arg = ''): string
    {
        $cmd = "(" . $serial . $cmd . $arg . ")";
        return $cmd;
    }
    public function gpsDDM2DD(string $gps)
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

    public function convertirCoordenadas($latitud, $longitud)
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
}
