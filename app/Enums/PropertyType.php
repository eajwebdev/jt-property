<?php

namespace App\Enums;

enum PropertyType: string
{
    case House = 'house';
    case Apartment = 'apartment';
    case BoardingHouse = 'boarding_house';
    case Dormitory = 'dormitory';
    case Rooms = 'rooms';
    case Bedspaces = 'bedspaces';
    case Other = 'other';
}
