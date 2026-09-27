<?php

namespace App\Domain\Tenant\Enums;

enum MembershipStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Suspended = 'suspended';
}
