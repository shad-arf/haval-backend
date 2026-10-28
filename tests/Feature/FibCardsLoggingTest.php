<?php

namespace Tests\Feature;

use App\Services\FibCardsClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class FibCardsLoggingTest extends TestCase
{
    public function test_authentication_logs_only_endpoint_and_status_without_the_response_body(): void
    {
        config()->set('fib.uat_base_url', 'https://fib.test');
        config()->set('fib.environment', 'uat');
        config()->set('fib.hmac_secret', 'test-secret');
        config()->set('fib.log', true);
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->twice()->andReturn($logger);
        $logger->shouldReceive('info')->once()->with('[fib-cards] POST /api/v1/auth/token -> HTTP 200');
        $logger->shouldReceive('info')->once()->with('[fib-cards] POST /api/v1/auth/token -> HTTP 401');
        Http::fakeSequence()
            ->push(['accessToken' => 'private-test-token', 'extra' => 'private-response-data'])
            ->push(['description' => 'private-test-token'], 401);

        $client = app(FibCardsClient::class);
        $this->assertSame('private-test-token', $client->accessToken('order-1'));

        try {
            $client->accessToken('order-2');
            $this->fail('Rejected authentication must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('FIB rejected the credentials (HTTP 401).', $exception->getMessage());
        }
    }
}
