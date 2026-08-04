<?php

declare(strict_types=1);

namespace Beryildiz\DB2Driver;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class DB2ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bindet den Connector im Container, damit Illuminates ConnectionFactory ihn lazy
        // auflösen kann (siehe ConnectionFactory::createConnector()). Ohne diese Bindung würde
        // die von Laravel erzeugte lazy-PDO-Closure beim ersten Aufruf mit
        // "Unsupported driver [db2]" fehlschlagen, da 'db2' kein Core-Treiber ist.
        $this->app->bind('db.connector.db2', DB2Connector::class);

        Connection::resolverFor('db2', function ($connection, $database, $prefix, $config) {
            // $connection ist bereits eine von Laravel erzeugte Closure, die PDO erst bei
            // Bedarf auflöst (lazy). Sie NICHT sofort aufrufen, sonst öffnet jedes
            // DB::connection('db2') sofort einen ODBC-Socket.
            return new DB2Connection(
                $connection,
                $database,
                $prefix,
                $config
            );
        });
    }
}
