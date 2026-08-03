<?php

namespace App\Enums;

enum AccessType: string
{
    case Unlimited = 'unlimited';
    case Metered = 'metered';
    case Denied = 'denied';
}
