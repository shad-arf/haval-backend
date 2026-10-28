<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * FIB Cards Payment Gateway (ACS24) — Visa, Mastercard and other cards.
 *
 *   POST /api/v1/auth/token        -> accessToken (single use, bound to one orderId)
 *   POST /api/v1/payment/initiate  -> { redirectUrl, paymentId }
 *   POST /api/v1/payments/status   -> the authoritative payment result
 *
 * Every request carries a Bearer token minted for that one orderId and an
 * X-HMAC header: HMAC-SHA512, hex, over the request body canonicalised by
 * recursive case-insensitive key sort.
 *
 * This is a port of the WooCommerce gateway that was verified against FIB's
 * UAT environment; the behavioural notes below record what that testing found.
 */
class FibCardsClient
{
    private const TOKEN_PATH = '/api/v1/auth/token';

    private const INITIATE_PATH = '/api/v1/payment/initiate';

    private const STATUS_PATH = '/api/v1/payments/status';

    /** FIB answers "No transaction found" with a 404 until the card page is completed. */
    public const NO_TRANSACTION = 404;

    /** Confirmed against UAT: a completed payment reports paymentData.status "SUCCESS". */
    public const PAID_STATES = [
        'SUCCESS', 'PAID', 'SUCCESSFUL', 'SUCCEEDED', 'COMPLETED', 'COMPLETE',
        'APPROVED', 'CAPTURED', 'SETTLED', 'PAYMENT_SUCCESS',
    ];

    /** The approval code that must accompany a genuine success. */
    public const APPROVED_RETURN_CODE = '000';

    public const FAILED_STATES = [
        'DECLINED', 'FAILED', 'FAILURE', 'REJECTED', 'UNSUCCESSFUL',
        'DENIED', 'PAYMENT_FAILED',
    ];

    public const CANCELLED_STATES = [
        'CANCELLED', 'CANCELED', 'VOID', 'VOIDED', 'EXPIRED', 'TIMEOUT', 'TIMED_OUT',
    ];

    /**
     * States that mean "not finished yet" — the order stays pending.
     *
     * "ERROR" belongs here, not in FAILED_STATES, and the difference matters.
     * Confirmed against UAT on 2026-09-21: simply *opening* the FIB card page
     * creates the transaction with status "ERROR", before the customer has
     * typed anything. The same payment reported "SUCCESS" once it completed.
     * Treating "ERROR" as a decline would fail every order the moment the
     * customer reached the card page.
     */
    public const IN_PROGRESS_STATES = [
        'ERROR', 'PENDING', 'INITIATED', 'INITIALISED', 'INITIALIZED', 'CREATED',
        'NEW', 'IN_PROGRESS', 'PROCESSING', 'UNPAID', 'WAITING',
    ];

    public function isConfigured(): bool
    {
        if (! config('fib.enabled')) {
            return false;
        }

        if ($this->baseUrl() === '' || (string) config('fib.hmac_secret') === '') {
            return false;
        }

        foreach (['terminal_number', 'institution', 'merchant_id', 'acquirer'] as $key) {
            if (trim((string) config('fib.'.$key)) === '') {
                return false;
            }
        }

        return true;
    }

    public function baseUrl(): string
    {
        $url = config('fib.environment') === 'production'
            ? (string) config('fib.production_base_url')
            : (string) config('fib.uat_base_url');

        return rtrim(trim($url), '/');
    }

    /**
     * Start a payment.
     *
     * @param  array<string, string>  $clientInfo
     * @return array{redirect_url: string, payment_id: string}
     *
     * @throws RuntimeException
     */
    public function initiatePayment(
        string $fibOrderId,
        int $amount,
        array $clientInfo,
        string $redirectUrl,
        string $callbackUrl,
    ): array {
        $token = $this->accessToken($fibOrderId);

        $body = [
            'clientInfo' => $clientInfo,
            'amount' => (string) $amount,
            'currency' => 'IQD',
            'redirectUrl' => $redirectUrl,
            'orderId' => $fibOrderId,
        ];

        // FIB cannot reach a private address, so during local development the
        // callback is omitted rather than sent as something unreachable.
        if ($callbackUrl !== '') {
            $body['callbackUrl'] = $callbackUrl;
        }

        $result = $this->post(self::INITIATE_PATH, $body, $token);

        $redirect = (string) ($result['data']['redirectUrl'] ?? '');
        $paymentId = (string) ($result['data']['paymentId'] ?? '');

        if ($result['http'] < 200 || $result['http'] >= 300 || $redirect === '') {
            throw new RuntimeException(sprintf(
                'FIB did not return a card payment page (HTTP %d).',
                $result['http'],
            ));
        }

        return ['redirect_url' => $redirect, 'payment_id' => $paymentId];
    }

    /**
     * Check Payment Status — the only thing that may mark an order paid.
     *
     * @return array{http: int, data: array<string, mixed>}
     *
     * @throws RuntimeException
     */
    public function paymentStatus(string $fibOrderId, ?string $paymentId): array
    {
        $token = $this->accessToken($fibOrderId);

        return $this->post(self::STATUS_PATH, [
            'paymentId' => (string) $paymentId,
            'orderId' => $fibOrderId,
        ], $token);
    }

    /**
     * Mint an access token. FIB binds each token to one orderId and treats it
     * as single use, so these are never cached — every call mints its own.
     *
     * @throws RuntimeException
     */
    public function accessToken(string $fibOrderId): string
    {
        $result = $this->post(self::TOKEN_PATH, [
            'terminalNumber' => (string) config('fib.terminal_number'),
            'institution' => (string) config('fib.institution'),
            'merchantId' => (string) config('fib.merchant_id'),
            'acquirer' => (string) config('fib.acquirer'),
            'dateTime' => self::now(),
            'orderId' => $fibOrderId,
        ]);

        if ($result['http'] < 200 || $result['http'] >= 300) {
            throw new RuntimeException(sprintf(
                'FIB rejected the credentials (HTTP %d).',
                $result['http'],
            ));
        }

        $token = (string) ($result['data']['accessToken'] ?? '');

        if ($token === '') {
            throw new RuntimeException('FIB returned no access token.');
        }

        return $token;
    }

    /** FIB's dateTime format is d/m/Y H:i:s in Baghdad time. */
    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Baghdad')))->format('d/m/Y H:i:s');
    }

    /**
     * Canonical form of a request body, byte-for-byte what FIB signs.
     *
     * Object keys sorted case-insensitively, applied recursively to nested
     * objects, lists left untouched. JSON_UNESCAPED_SLASHES and
     * JSON_UNESCAPED_UNICODE are required — PHP escapes "/" and non-ASCII by
     * default, which JavaScript's JSON.stringify does not, and the two must
     * agree or the signature fails.
     *
     * @param  array<string, mixed>  $body
     */
    public static function canonicalJson(array $body): string
    {
        return (string) json_encode(self::sortKeys($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private static function sortKeys($value)
    {
        if (! is_array($value) || array_is_list($value)) {
            return $value;
        }

        uksort($value, static fn ($a, $b): int => strcmp(strtolower((string) $a), strtolower((string) $b)));

        foreach ($value as $key => $item) {
            if (is_array($item) && ! array_is_list($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        return $value;
    }

    public static function sign(string $canonicalJson, string $secret): string
    {
        return hash_hmac('sha512', $canonicalJson, $secret);
    }

    /** Verify the JSON body, preserving objects and list order when canonicalising. */
    public function verifyCallback(string $body, ?string $signature): bool
    {
        $secret = (string) config('fib.hmac_secret');

        if ($secret === '' || $signature === null || ! preg_match('/\A[0-9a-fA-F]{128}\z/', $signature)) {
            return false;
        }

        $decoded = json_decode($body);

        if (! $decoded instanceof \stdClass || json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        $canonical = json_encode($this->sortCallbackKeys($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $canonical !== false && hash_equals(self::sign($canonical, $secret), strtolower($signature));
    }

    private function sortCallbackKeys(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            uksort($properties, static fn ($a, $b): int => strcmp(strtolower((string) $a), strtolower((string) $b)));

            return (object) array_map($this->sortCallbackKeys(...), $properties);
        }

        if (is_array($value)) {
            return array_map($this->sortCallbackKeys(...), $value);
        }

        return $value;
    }

    /**
     * Fold text down to printable ASCII.
     *
     * FIB's HMAC verification rejects any request body containing a non-ASCII
     * character. Reproduced against UAT on 2026-09-21: a body whose only
     * non-ASCII content is a single "é" is answered 401 "Invalid hmac
     * signature", as is Arabic text, under every combination of escaped and
     * unescaped unicode and slashes. The identical body with ASCII text is
     * accepted.
     *
     * Customer names and addresses in this store are routinely Arabic or
     * Kurdish, so clientInfo is transliterated before signing. It is
     * descriptive metadata only — FIB matches the payment on orderId,
     * paymentId and amount, and the customer's real name stays on our order.
     */
    public static function ascii(string $text, string $fallback = ''): string
    {
        if (preg_match('/[^\x20-\x7E]/', $text)) {
            $text = self::transliterate($text);
        }

        // Whatever the transliterator could not represent is dropped rather
        // than sent as a replacement byte that would fail the signature again.
        $text = (string) preg_replace('/[^\x20-\x7E]/', '', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return $text !== '' ? $text : $fallback;
    }

    private static function transliterate(string $text): string
    {
        if (function_exists('transliterator_transliterate')) {
            $converted = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);

            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

            if (is_string($converted)) {
                return $converted;
            }
        }

        return $text;
    }

    public static function clamp(string $text, int $length): string
    {
        return mb_substr($text, 0, $length);
    }

    /**
     * One signed POST.
     *
     * Returns the status code alongside the payload for every JSON answer,
     * including error ones — a 404 here means "no transaction yet", not a
     * failure, so the caller must be able to see it.
     *
     * @param  array<string, mixed>  $body
     * @return array{http: int, data: array<string, mixed>}
     *
     * @throws RuntimeException
     */
    private function post(string $path, array $body, ?string $bearer = null): array
    {
        $base = $this->baseUrl();
        $secret = (string) config('fib.hmac_secret');

        if ($base === '' || $secret === '') {
            throw new RuntimeException('The FIB card gateway is not configured.');
        }

        $json = self::canonicalJson($body);

        if (preg_match('/[^\x20-\x7E]/', $json)) {
            $this->log(sprintf('Warning: the body for %s contains non-ASCII characters, which FIB rejects as an invalid signature.', $path));
        }

        $headers = ['X-HMAC' => self::sign($json, $secret)];

        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        try {
            // The signed bytes are the bytes sent — never re-encode between the two.
            $response = Http::withHeaders($headers)
                ->withBody($json, 'application/json')
                ->acceptJson()
                ->timeout(max(1, (int) config('fib.timeout')))
                ->post($base.$path);
        } catch (ConnectionException $exception) {
            $this->log(sprintf('POST %s failed: connection error', $path));

            throw new RuntimeException('The card payment service is not responding.', 0, $exception);
        }

        $http = $response->status();
        $data = $response->json();

        $this->log(sprintf('POST %s -> HTTP %d', $path, $http));

        if (! is_array($data)) {
            throw new RuntimeException(sprintf('FIB returned a non-JSON response (HTTP %d).', $http));
        }

        return ['http' => $http, 'data' => $data];
    }

    /** The secret and the signature are never logged. */
    private function log(string $message): void
    {
        if (config('fib.log')) {
            Log::channel(config('logging.default'))->info('[fib-cards] '.$message);
        }
    }
}
