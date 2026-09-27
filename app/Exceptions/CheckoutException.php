<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A checkout failure with a message that is safe to show the customer.
 *
 * These describe something the customer can act on — a part that sold out, a
 * quantity that is no longer available — so the message is deliberately
 * surfaced rather than swallowed into a generic error.
 */
class CheckoutException extends RuntimeException {}
