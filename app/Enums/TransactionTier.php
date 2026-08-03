<?php

namespace App\Enums;

enum TransactionTier: string
{
    case Premium = 'premium';
    case MidRange = 'mid_range';
    case Standard = 'standard';
}
