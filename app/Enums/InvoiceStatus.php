<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Finalized = 'finalized';
    case Void = 'void';
}
