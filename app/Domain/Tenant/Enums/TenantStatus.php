<?php

namespace App\Domain\Tenant\Enums;

enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
