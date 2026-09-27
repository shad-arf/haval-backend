<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FibQrClient
{
    public function isConfigured(): bool
    {
        return (bool) config('fib_qr.enabled')
            && str_starts_with((string) config('fib_qr.base_url'), 'https://')
            && trim((string) config('fib_qr.client_id')) !== ''
            && trim((string) config('fib_qr.client_secret')) !== '';
    }

    /** @return array<string, mixed> */
    public function createPayment(int $amount, string $reference): array
    {
        $body = [
            'monetaryValue' => ['amount' => number_format($amount, 2, '.', ''), 'currency' => 'IQD'],
            'description' => mb_substr('Masdar order '.$reference, 0, 50),
        ];

        $callbackUrl = trim((string) config('fib_qr.callback_url'));
        if (str_starts_with($callbackUrl, 'https://')) {
            $body['statusCallbackUrl'] = $callbackUrl;
        }

        try {
            $response = Http::timeout((int) config('fib_qr.timeout'))
                ->withToken($this->accessToken())->acceptJson()
                ->post($this->url('/protected/v1/payments'), $body);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('FIB QR payment creation could not connect.', previous: $exception);
        }

        $data = $response->json();
        if (! in_array($response->status(), [201, 202], true) || ! is_array($data)
            || ! is_string($data['paymentId'] ?? null) || ! is_string($data['qrCode'] ?? null)
            || ! preg_match('~^data:image/png;base64,[A-Za-z0-9+/=]+$~', $data['qrCode'])) {
            throw new RuntimeException('FIB QR payment creation failed (HTTP '.$response->status().').');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function paymentStatus(string $paymentId): array
    {
        try {
            $response = Http::timeout((int) config('fib_qr.timeout'))
                ->withToken($this->accessToken())->acceptJson()
                ->get($this->url('/protected/v1/payments/'.rawurlencode($paymentId).'/status'));
        } catch (ConnectionException $exception) {
            throw new RuntimeException('FIB QR status could not connect.', previous: $exception);
        }

        $data = $response->json();
        if (! $response->successful() || ! is_array($data)) {
            throw new RuntimeException('FIB QR status failed (HTTP '.$response->status().').');
        }

        return $data;
    }

    private function accessToken(): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('FIB QR is not configured.');
        }

        $cacheKey = 'fib-qr-token:'.hash('sha256', $this->url('').config('fib_qr.client_id').config('fib_qr.client_secret'));
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::timeout((int) config('fib_qr.timeout'))->asForm()->acceptJson()->post(
                $this->url('/auth/realms/fib-online-shop/protocol/openid-connect/token'),
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => config('fib_qr.client_id'),
                    'client_secret' => config('fib_qr.client_secret'),
                ],
            );
        } catch (ConnectionException $exception) {
            throw new RuntimeException('FIB QR authentication could not connect.', previous: $exception);
        }

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('FIB QR authentication failed (HTTP '.$response->status().').');
        }

        $expiresIn = (int) $response->json('expires_in');
        if ($expiresIn > 60) {
            Cache::put($cacheKey, $token, $expiresIn - 30);
        }

        return $token;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('fib_qr.base_url'), '/').$path;
    }
}
