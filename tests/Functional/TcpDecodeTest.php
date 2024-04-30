<?php

namespace Tests\Functional;

use PHPUnit\Framework\TestCase;
use Jhamner\Tk103Parser\TkParser;

class TcpDecodeTest extends TestCase
{
    /** @test */
    public function can_decode_imei()
    {
        $imei = (new TkParser('tcp'))->decodeImei(
            '(057045206556BP05357857045206556190503A5210.8942N00428.4043E000.0134955000.0000000000L00000000)'
            /* this returns Array (
                        [0] => (057045206556BP05357857045206556190503A5210.8942N00428.4043E000.0134955000.0000000000L00000000)
                        [1] => 057045206556   --> serial number (12 bytes)
                        [2] => BP05 -> command code
                        [3] => 357857045206556190503A5210.8942N00428.4043E000.0134955000.0000000000L00000000 --> data
                    )
                    */
        );
        $this->assertEquals('057045206556', $imei->getImei());
    }
}
