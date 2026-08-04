<?php

declare(strict_types=1);

namespace Beryildiz\DB2Driver\Tests;

use Beryildiz\DB2Driver\DB2DriverServiceProvider;
use Beryildiz\DB2Driver\DB2ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            DB2DriverServiceProvider::class,
            DB2ServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
    }
}
