<?php

declare(strict_types=1);

namespace Jhamner\Tk103Parser\Model;

use JsonSerializable;

abstract class Model implements JsonSerializable
{
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
