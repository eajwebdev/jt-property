<?php

namespace App\Enums;

enum UnitStatus: string
{
    case Available = 'available';
    case Maintenance = 'maintenance';
    case Unavailable = 'unavailable';
}
