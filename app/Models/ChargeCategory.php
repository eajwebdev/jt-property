<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargeCategory extends Model
{
    public const KINDS = ['rent', 'appliance', 'utility', 'service', 'other'];

    protected $fillable = ['name', 'kind'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }
}
