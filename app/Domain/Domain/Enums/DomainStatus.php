<?php

namespace App\Domain\Domain\Enums;

enum DomainStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Disabled = 'disabled';
}
