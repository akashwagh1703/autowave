<?php

namespace App\Domain\Domain\Enums;

enum DomainType: string
{
    case Subdomain = 'subdomain';
    case Custom = 'custom';
}
