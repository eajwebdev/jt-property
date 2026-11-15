<?php

namespace App\Enums;

enum DepositStatus: string
{
    case Held = 'held';
    case Applied = 'applied';
    case Refunded = 'refunded';
}
