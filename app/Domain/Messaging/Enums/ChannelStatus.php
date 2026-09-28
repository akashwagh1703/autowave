<?php

namespace App\Domain\Messaging\Enums;

enum ChannelStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::Disconnected => 'Not connected',
        };
    }
}
