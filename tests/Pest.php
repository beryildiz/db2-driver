<?php

declare(strict_types=1);

use Beryildiz\DB2Driver\DB2Connection;
use Beryildiz\DB2Driver\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Erstellt eine minimale DB2Connection für Unit-Tests, die Grammar-/Blueprint-Konstruktoren
 * benötigen (Laravel 12 verlangt eine Connection im Konstruktor von Illuminate\Database\Grammar
 * bzw. Illuminate\Database\Schema\Blueprint). Der PDO-Parameter bleibt eine No-Op-Closure,
 * da diese Tests keine echte Datenbankverbindung öffnen.
 */
function makeConnection(): DB2Connection
{
    $connection = new DB2Connection(fn () => null);
    $connection->useDefaultSchemaGrammar();

    return $connection;
}
