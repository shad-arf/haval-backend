<?php

namespace App\Models;

use Database\Factories\FibPaymentAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FibPaymentAttempt extends Model
{
    /** @use HasFactory<FibPaymentAttemptFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_amount' => 'integer',
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
     * A fresh numeric order ID for one payment attempt.
     *
     * FIB requires this to be unique per attempt; the timestamp prefix keeps it
     * increasing and the random suffix avoids a collision within the same second.
     */
    public static function newFibOrderId(): string
    {
        return sprintf('%010d%05d', time(), random_int(0, 99999));
    }
}
