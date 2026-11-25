<?php

declare(strict_types=1);

namespace Tests\Feature\QaRegression;

use App\Shipping\ProviderRequestFailure;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use RuntimeException;
use Tests\Support\FulfilmentSafetyFixtures;
use Tests\TestCase;

/**
 * Go-live QA "fulfilment-safety", provider-neutral part: lifecycle schedules
 * never overlap, and only provider writes that never left the server are
 * retried. The provider cases live with their modules
 * (tests/Feature/<Module>/<Module>FulfilmentSafetyTest).
 */
final class FulfilmentSafetyTest extends TestCase
{
    use FulfilmentSafetyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startFulfilmentSafety();
    }

    protected function tearDown(): void
    {
        $this->endFulfilmentSafety();

        parent::tearDown();
    }

    public function test_lifecycle_schedules_never_overlap_and_run_on_one_server(): void
    {
        $events = collect(resolve(Schedule::class)->events());

        foreach (['shipping:fulfill-paid', 'shipping:refresh-tracking', 'shipping:sync-carriers', 'orders:expire-unpaid'] as $command) {
            $event = $events->first(static fn (Event $event): bool => str_contains((string) $event->command, $command));

            $this->assertInstanceOf(Event::class, $event, "{$command} is scheduled");
            $this->assertTrue($event->withoutOverlapping, "{$command} must not overlap itself");
            $this->assertTrue($event->onOneServer, "{$command} must run on one server");
            $this->assertLessThan(1440, $event->expiresAt, "{$command} overlap mutex must not block the schedule for a day");
        }
    }

    // ---- Transport failure classification ------------------------------------

    public function test_only_unsent_writes_are_retryable(): void
    {
        $readTimeout = $this->connectionFailure('POST', 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received');
        $emptyReply = $this->connectionFailure('POST', 'cURL error 52: Empty reply from server');
        $refused = $this->connectionFailure('POST', 'cURL error 7: Failed to connect to host port 443: Connection refused');
        $dns = $this->connectionFailure('POST', 'cURL error 6: Could not resolve host: delivery.example.test');
        $connectTimeout = $this->connectionFailure('POST', 'cURL error 28: Connection timed out after 5001 milliseconds');
        $sentThenTimeout = $this->connectionFailure('POST', 'cURL error 28: Connection timed out after 5001 milliseconds', ['errno' => 28, 'connect_time' => 0.12]);

        $this->assertFalse(ProviderRequestFailure::retryable($readTimeout, 'POST'));
        $this->assertFalse(ProviderRequestFailure::retryable($emptyReply, 'POST'));
        $this->assertFalse(ProviderRequestFailure::retryable($sentThenTimeout, 'POST'));
        $this->assertTrue(ProviderRequestFailure::retryable($refused, 'POST'));
        $this->assertTrue(ProviderRequestFailure::retryable($dns, null));
        $this->assertTrue(ProviderRequestFailure::retryable($connectTimeout, 'POST'));
        $this->assertTrue(ProviderRequestFailure::retryable($readTimeout, 'GET'));
        $this->assertFalse(ProviderRequestFailure::retryable(new RuntimeException('boom'), 'GET'));
    }

    public function test_real_curl_contexts_are_classified_by_bytes_sent_not_pretransfer_time(): void
    {
        // Handler contexts as real cURL reports them: pretransfer_time is
        // filled with the elapsed time even when nothing was sent.
        $refused = $this->connectionFailure('POST', 'cURL error 7: Failed to connect to 127.0.0.1:1 after 0 ms: Could not connect to server', [
            'errno' => 7, 'connect_time' => 0.0, 'pretransfer_time' => 0.00048, 'request_size' => 0, 'size_upload' => 0.0,
        ]);
        $dns = $this->connectionFailure('POST', 'cURL error 6: Could not resolve host: delivery.example.test', [
            'errno' => 6, 'connect_time' => 0.0, 'pretransfer_time' => 0.021829, 'request_size' => 0, 'size_upload' => 0.0,
        ]);
        $connectTimeout = $this->connectionFailure('POST', 'cURL error 28: Connection timed out after 2005 milliseconds', [
            'errno' => 28, 'connect_time' => 0.0, 'pretransfer_time' => 2.006182, 'request_size' => 0, 'size_upload' => 0.0,
        ]);
        $tlsHandshakeTimeout = $this->connectionFailure('POST', 'cURL error 28: SSL connection timeout', [
            'errno' => 28, 'connect_time' => 0.0012, 'pretransfer_time' => 0.0, 'request_size' => 0, 'size_upload' => 0.0,
        ]);
        $readTimeout = $this->connectionFailure('POST', 'cURL error 28: Operation timed out after 3001 milliseconds with 0 bytes received', [
            'errno' => 28, 'connect_time' => 0.001276, 'pretransfer_time' => 0.001647, 'request_size' => 127, 'size_upload' => 7.0,
        ]);
        $emptyReply = $this->connectionFailure('POST', 'cURL error 52: Empty reply from server', [
            'errno' => 52, 'connect_time' => 0.0011, 'pretransfer_time' => 0.0014, 'request_size' => 127, 'size_upload' => 7.0,
        ]);
        // A "Connection timed out" message never overrides bytes on the wire.
        $sentThenTimeout = $this->connectionFailure('POST', 'cURL error 28: Connection timed out after 5001 milliseconds', [
            'errno' => 28, 'connect_time' => 0.01, 'request_size' => 127,
        ]);

        $this->assertTrue(ProviderRequestFailure::notSent($refused));
        $this->assertTrue(ProviderRequestFailure::notSent($dns));
        $this->assertTrue(ProviderRequestFailure::notSent($connectTimeout));
        $this->assertTrue(ProviderRequestFailure::notSent($tlsHandshakeTimeout));
        $this->assertFalse(ProviderRequestFailure::notSent($readTimeout));
        $this->assertFalse(ProviderRequestFailure::notSent($emptyReply));
        $this->assertFalse(ProviderRequestFailure::notSent($sentThenTimeout));
        $this->assertTrue(ProviderRequestFailure::retryable($refused, 'POST'));
        $this->assertFalse(ProviderRequestFailure::retryable($readTimeout, 'POST'));
    }
}
