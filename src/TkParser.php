<?php

declare(strict_types=1);

namespace Jhamner\Tk103Parser;

use Jhamner\Tk103Parser\Model\Imei;
use Jhamner\Tk103Parser\Protocol\Tcp\Packet;

class TkParser
{
    private DecoderInterface $decoder;

    public function __construct(string $protocol)
    {
        $namespace = 'Jhamner\\Tk103Parser\\Protocol\\' . ucfirst($protocol) . '\\';

        /** @var DecoderInterface $decoder */
        $decoder = new ($namespace . 'Decoder');
        $this->decoder = $decoder;
    }

    public function decodeData(string $data)
    {
        return $this->decoder->decodeData($data);
    }

    public function decodeImei(string $data): Imei
    {
        return $this->decoder->decodeImei($data);
    }
}
