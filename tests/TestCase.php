<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');
        $database   = config("database.connections.{$connection}.database");

        if (!app()->environment('testing')) {
            throw new RuntimeException(
                'Tests must run in the testing environment.'
            );
        }

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Unsafe test database configuration: {$connection} / {$database}. "
                .'Tests must use SQLite :memory:.'
            );
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (!Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
