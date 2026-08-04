<?php

declare(strict_types=1);

namespace Beryildiz\DB2Driver;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class DB2ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Connection::resolverFor('db2', function ($connection, $database, $prefix, $config) {
            $connector = new DB2Connector();

            return new DB2Connection(
                $connector->connect($config),
                $database,
                $prefix,
                $config
            );
        });
    }
}
