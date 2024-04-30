<?php

declare(strict_types=1);

namespace Jhamner\Tk103Parser;

use Jhamner\Tk103Parser\Model\Imei;
use Jhamner\Tk103Parser\Protocol\Tcp\Packet;


interface DecoderInterface
{
    public function decodeImei(string $payload): Imei;

    public function decodeData(string $payload);
}
