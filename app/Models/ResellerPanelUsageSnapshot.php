<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerPanelUsageSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'reseller_id',
        'panel_id',
        'total_usage_bytes',
        'active_config_count',
        'captured_at',
    ];

    protected $casts = [
        'total_usage_bytes' => 'integer',
        'active_config_count' => 'integer',
        'captured_at' => 'datetime',
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
