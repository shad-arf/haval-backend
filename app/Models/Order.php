<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /** Awaiting payment, or awaiting delivery in the case of cash on delivery. */
    public const STATUS_PENDING = 'pending_payment';

    public const STATUS_PAID = 'paid';

    /** Placed, to be paid in cash when the parts arrive. */
    public const STATUS_AWAITING_DELIVERY = 'awaiting_delivery';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** The gateway said something contradictory; a human decides. */
    public const STATUS_ON_HOLD = 'on_hold';

    public const METHOD_FIB_CARD = 'fib_card';

    public const METHOD_FIB_QR = 'fib_qr';

    public const METHOD_COD = 'cod';

    /**
     * Statuses that mean the payment question is settled, so reconciliation
     * against the gateway should stop.
     */
    public const SETTLED_STATUSES = [
        self::STATUS_PAID,
        self::STATUS_AWAITING_DELIVERY,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_ON_HOLD,
    ];

    /*
    |--------------------------------------------------------------------------
    | Fulfilment
    |--------------------------------------------------------------------------
    |
    | Where the parts are, which is independent of whether they have been paid
    | for — see the add_fulfilment_status_to_orders_table migration. Payment
    | moves on its own through the gateway and the reconciler; fulfilment is
    | moved by a human in the dashboard.
    */

    /** Nobody has picked the order up off the queue yet. */
    public const FULFILMENT_NOT_STARTED = 'not_started';

    /** A part is not on the shelf and is being brought in from a supplier. */
    public const FULFILMENT_AWAITING_STOCK = 'awaiting_stock';

    public const FULFILMENT_PREPARING = 'preparing';

    /** Picked and packed, waiting for a driver or a counter collection. */
    public const FULFILMENT_READY = 'ready';

    public const FULFILMENT_OUT_FOR_DELIVERY = 'out_for_delivery';

    public const FULFILMENT_DELIVERED = 'delivered';

    public const FULFILMENT_RETURNED = 'returned';

    /**
     * Every stage an order may be set to, in the order they are shown.
     * `returned` sits outside the forward chain — see FULFILMENT_CHAIN.
     */
    public const FULFILMENT_STATUSES = [
        self::FULFILMENT_NOT_STARTED,
        self::FULFILMENT_AWAITING_STOCK,
        self::FULFILMENT_PREPARING,
        self::FULFILMENT_READY,
        self::FULFILMENT_OUT_FOR_DELIVERY,
        self::FULFILMENT_DELIVERED,
        self::FULFILMENT_RETURNED,
    ];

    /**
     * The happy path, used to draw the progress chain. `returned` is left out
     * because it is an exit, not a step towards delivery.
     */
    public const FULFILMENT_CHAIN = [
        self::FULFILMENT_NOT_STARTED,
        self::FULFILMENT_AWAITING_STOCK,
        self::FULFILMENT_PREPARING,
        self::FULFILMENT_READY,
        self::FULFILMENT_OUT_FOR_DELIVERY,
        self::FULFILMENT_DELIVERED,
    ];

    /**
     * Payment states in which handling the goods makes sense: the order is
     * either paid, or placed as cash on delivery and due to be taken out.
     * An unpaid card order must not be picked, and a cancelled or failed one
     * must not keep moving down the line.
     */
    public const FULFILLABLE_STATUSES = [
        self::STATUS_PAID,
        self::STATUS_AWAITING_DELIVERY,
    ];

    protected $guarded = [];

    /**
     * The column default alone is not enough: it applies on insert, so a model
     * that was just `create()`d reports a null stage until it is re-read, and
     * the OrderResource returned straight after checkout would say the same.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'fulfilment_status' => self::FULFILMENT_NOT_STARTED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'total' => 'integer',
            'paid_at' => 'datetime',
            'fulfilment_updated_at' => 'datetime',
            'dolibarr_synced_at' => 'datetime',
            'dolibarr_order_id' => 'integer',
            'dolibarr_invoice_id' => 'integer',
        ];
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<FibPaymentAttempt, $this>
     */
    public function fibAttempts(): HasMany
    {
        return $this->hasMany(FibPaymentAttempt::class);
    }

    /** @return HasMany<FibQrPaymentAttempt, $this> */
    public function fibQrAttempts(): HasMany
    {
        return $this->hasMany(FibQrPaymentAttempt::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isSettled(): bool
    {
        return in_array($this->status, self::SETTLED_STATUSES, true);
    }

    /**
     * A paid order has an invoice the customer can view; an unpaid one does not.
     */
    public function hasInvoice(): bool
    {
        return $this->isPaid();
    }

    /**
     * Whether the order is in a payment state where the parts should be
     * handled at all.
     */
    public function isFulfillable(): bool
    {
        return in_array($this->status, self::FULFILLABLE_STATUSES, true);
    }

    /**
     * Move the order to a fulfilment stage.
     *
     * Deliberately not a strict forward-only machine. A picker who marks an
     * order "ready" by mistake has to be able to put it back, and a delivery
     * that fails goes from "out for delivery" to "returned" and then round
     * again — encoding a single legal path would only mean editing rows by
     * hand. The one rule enforced is the payment guard above.
     */
    public function moveFulfilmentTo(string $stage): bool
    {
        if (! in_array($stage, self::FULFILMENT_STATUSES, true) || ! $this->isFulfillable()) {
            return false;
        }

        $this->forceFill([
            'fulfilment_status' => $stage,
            'fulfilment_updated_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Constant-time check of the key that stands in for a customer login.
     */
    public function accessKeyMatches(string $candidate): bool
    {
        return hash_equals($this->access_key, $candidate);
    }

    public static function newAccessKey(): string
    {
        return Str::random(48);
    }
}
