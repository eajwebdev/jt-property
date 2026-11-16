<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    case Charge = 'charge';
    case Opening = 'opening';
    case Payment = 'payment';
    case Credit = 'credit';
    case Adjustment = 'adjustment';
    case Refund = 'refund';
    case Writeoff = 'writeoff';
    case Reversal = 'reversal';
}
