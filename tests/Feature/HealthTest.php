<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_it_reports_application_health(): void
    {
        Cache::put('health-check', 'ok', 5);

        $this->get('/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'cache' => 'ok',
            ]);
    }
}
