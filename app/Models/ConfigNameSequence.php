<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfigNameSequence extends Model
{
    use HasFactory;

    protected $fillable = [
        'reseller_id',
        'panel_id',
        'next_seq',
    ];

    protected $casts = [
        'next_seq' => 'integer',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(Panel::class);
    }
}
