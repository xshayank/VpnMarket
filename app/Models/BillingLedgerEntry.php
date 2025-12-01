<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingLedgerEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'reseller_id',
        'reseller_config_id',
        'action_type',
        'charged_bytes',
        'amount_charged',
        'price_per_gb',
        'wallet_balance_before',
        'wallet_balance_after',
        'meta',
    ];

    protected $casts = [
        'charged_bytes' => 'integer',
        'amount_charged' => 'integer',
        'price_per_gb' => 'integer',
        'wallet_balance_before' => 'integer',
        'wallet_balance_after' => 'integer',
        'meta' => 'array',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(ResellerConfig::class, 'reseller_config_id');
    }
}
