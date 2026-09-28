<?php

namespace App\Domain\Automation\Enums;

enum StepType: string
{
    case Condition = 'condition';
    case Wait = 'wait';
    case Action = 'action';
}
