<?php

declare(strict_types=1);

namespace Jhamner\Tk103Parser\Model;

use Jhamner\Tk103Parser\Exception\InvalidArgumentException;

class Imei extends Model
{
    private const IMEI_LENGTH = 12;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(private readonly string $imei)
    {
        if (!$this->isValidImei() || strlen($this->imei) !== self::IMEI_LENGTH) {
            throw new InvalidArgumentException("IMEI number is not valid.");
        }
    }

    public function getImei(): string
    {
        return $this->imei;
    }

    public function __toString(): string
    {
        return $this->getImei();
    }

    public function isValidImei(): bool
    {
        for ($i = 0; $i < self::IMEI_LENGTH; $i++) {
            if (!is_numeric($this->imei[$i])) {
                return false;
            }
        }
        return true;
    }
}
