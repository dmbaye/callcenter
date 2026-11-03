<?php

namespace Tests;

use App\Services\AsteriskPjsip;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Run the Asterisk tables on the in-memory test database.
        config(['asterisk.connection' => config('database.default')]);
        AsteriskPjsip::flushAsteriskColumnCache();

        // Feature tests assert Inertia responses; they do not need built assets.
        $this->withoutVite();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
