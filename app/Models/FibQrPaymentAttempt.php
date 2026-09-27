<?php

namespace App\Models;

use Database\Factories\FibQrPaymentAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FibQrPaymentAttempt extends Model
{
    /** @use HasFactory<FibQrPaymentAttemptFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expected_amount' => 'integer', 'valid_until' => 'datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
