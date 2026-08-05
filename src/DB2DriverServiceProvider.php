<?php

declare(strict_types=1);

namespace Beryildiz\DB2Driver;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DB2DriverServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        // Kein publishesServiceProvider(): spatie würde dafür
        // resources/stubs/DB2ServiceProvider.php.stub erwarten (siehe
        // ProcessServiceProviders::bootPackageServiceProviders()). Der Provider
        // wird ohnehin über composer.json "extra.laravel.providers" auto-discovered,
        // ein Publishing ist daher weder nötig noch sinnvoll.
        $package->name('db2-driver');
    }
}
