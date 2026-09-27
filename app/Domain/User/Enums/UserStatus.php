<?php

namespace App\Domain\User\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
