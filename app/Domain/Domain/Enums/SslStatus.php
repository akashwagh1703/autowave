<?php

namespace App\Domain\Domain\Enums;

enum SslStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
}
