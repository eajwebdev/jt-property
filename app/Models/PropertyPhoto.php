<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyPhoto extends Model
{
    protected $fillable = ['property_id', 'path', 'caption', 'sort', 'width', 'height', 'uploaded_by'];

    protected function casts(): array
    {
        return ['sort' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** Relative URL (works on any host/port), cache-busted by the file name. */
    public function url(): string
    {
        return route('property-photos.show', $this, false);
    }

    /** @return array{id:int, url:string, caption:?string, width:?int, height:?int} */
    public function toCard(): array
    {
        return ['id' => $this->id, 'url' => $this->url(), 'caption' => $this->caption, 'width' => $this->width, 'height' => $this->height];
    }
}
