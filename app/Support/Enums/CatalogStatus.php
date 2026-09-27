<?php

namespace App\Support\Enums;

/**
 * Lifecycle of platform catalogue items (business types, engines, modules).
 */
enum CatalogStatus: string
{
    case Active = 'active';
    case Draft = 'draft';
    case Deprecated = 'deprecated';
}
