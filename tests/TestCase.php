<?php

namespace Tests;

use App\Support\OutboundUrlGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runs after the app boots and before database traits migrate, so a mis-pointed
     * connection aborts the run before a single query is issued.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        TestDatabaseGuard::assertSafe($this->app);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
        Mail::fake();

        // Tests never look up DNS: every made-up host name counts as one public address. Tests of
        // the private-network check bind their own resolver.
        $this->app->instance(OutboundUrlGuard::class, new class extends OutboundUrlGuard
        {
            public function resolve(string $host): array
            {
                $host = trim($host, '[]');

                return filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ['93.184.215.14'];
            }
        });
    }
}
