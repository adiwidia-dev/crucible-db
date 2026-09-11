<?php

namespace App\Services\NativeProxy;

use App\Events\NativeProxyLeaseRevoked;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class LeaseRevocationPublisher
{
    public function publish(NativeProxyLeaseRevoked $event): void
    {
        if ($event->leaseIds === []) {
            return;
        }

        try {
            Redis::publish((string) config('native_proxy.revocation_channel'), json_encode([
                'lease_ids' => $event->leaseIds,
                'reason' => $event->reason,
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            // A Go heartbeat re-evaluates the durable policy every two seconds and
            // closes the connection if this best-effort immediate signal is missed.
            Log::warning('Native proxy revocation publish failed.', [
                'lease_count' => count($event->leaseIds),
                'reason' => $event->reason,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
