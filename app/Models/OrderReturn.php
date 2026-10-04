<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods sent back on a paid order. Pushed to Dolibarr as a credit note
 * against the order's invoice — see DolibarrOrderPusher::pushReturn.
 */
class OrderReturn extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'dolibarr_credit_note_id' => 'integer',
            'dolibarr_refunded_at' => 'datetime',
            'dolibarr_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<OrderReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class);
    }

    public function isErpSynced(): bool
    {
        return $this->dolibarr_credit_note_id !== null
            && ($this->dolibarr_refunded_at !== null || (int) config('dolibarr.bank_account_id') <= 0);
    }
}
