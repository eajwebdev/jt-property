<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per setting key. Read and written through App\Support\Settings. */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
