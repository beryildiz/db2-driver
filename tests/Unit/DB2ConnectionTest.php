<?php

declare(strict_types=1);

use Beryildiz\DB2Driver\DB2Connection;
use Beryildiz\DB2Driver\DB2Processor;
use Beryildiz\DB2Driver\DB2QueryGrammar;
use Beryildiz\DB2Driver\Schema\DB2Builder;
use Beryildiz\DB2Driver\Schema\DB2SchemaGrammar;

/**
 * DB2Connection, die statement() mitschreibt statt es auszuführen.
 *
 * @param  array<string, mixed>  $config
 */
function makeRecordingConnection(array $config = ['schema' => 'MYLIB']): DB2Connection
{
    return new class(fn () => null, 'testdb', '', $config) extends DB2Connection
    {
        /** @var list<array{sql: string, bindings: array<int, mixed>}> */
        public array $statements = [];

        public function statement($query, $bindings = []): bool
        {
            $this->statements[] = ['sql' => $query, 'bindings' => $bindings];

            return true;
        }
    };
}

it('normalisiert das konfigurierte Schema auf Großbuchstaben', function () {
    expect(makeRecordingConnection(['schema' => 'mylib'])->getDefaultSchema())->toBe('MYLIB');
});

it('verträgt eine Config ohne schema-Key', function () {
    // Das Original rief strtoupper(null) auf -> Deprecation unter PHP 8.1+.
    expect(makeRecordingConnection([])->getDefaultSchema())->toBe('');
});

it('setCurrentSchema sendet DEFAULT als literales SQL-Schlüsselwort', function () {
    // Als Binding gesendet würde DB2 auf ein Schema wechseln, das wörtlich "DEFAULT" heißt.
    $connection = makeRecordingConnection();
    $connection->setCurrentSchema('');

    expect($connection->statements)->toBe([
        ['sql' => 'SET SCHEMA DEFAULT', 'bindings' => []],
    ]);
});

it('setCurrentSchema sendet einen konkreten Schemanamen als Binding in Großbuchstaben', function () {
    $connection = makeRecordingConnection();
    $connection->setCurrentSchema('otherlib');

    expect($connection->statements)->toBe([
        ['sql' => 'SET SCHEMA ?', 'bindings' => ['OTHERLIB']],
    ]);
});

it('resetCurrentSchema wechselt zurück auf das Default-Schema', function () {
    $connection = makeRecordingConnection(['schema' => 'MYLIB']);
    $connection->resetCurrentSchema();

    expect($connection->statements)->toBe([
        ['sql' => 'SET SCHEMA ?', 'bindings' => ['MYLIB']],
    ]);
});

it('executeCommand ruft QCMDEXC mit gebundenem Kommando auf', function () {
    $connection = makeRecordingConnection();
    $connection->executeCommand('CHGJOB INQMSGRPY(*SYSRPYL)');

    expect($connection->statements)->toBe([
        ['sql' => 'CALL QSYS2.QCMDEXC(?)', 'bindings' => ['CHGJOB INQMSGRPY(*SYSRPYL)']],
    ]);
});

it('liefert die DB2-eigenen Grammars und den DB2Processor', function () {
    $connection = makeRecordingConnection();

    expect($connection->getQueryGrammar())->toBeInstanceOf(DB2QueryGrammar::class);
    expect($connection->getPostProcessor())->toBeInstanceOf(DB2Processor::class);
});

it('getSchemaBuilder initialisiert die Schema-Grammar bei Bedarf', function () {
    // Der Connection-Konstruktor setzt nur Query-Grammar und Processor; die
    // Schema-Grammar wird erst durch getSchemaBuilder() aufgelöst.
    $connection = makeRecordingConnection();

    expect($connection->getSchemaGrammar())->toBeNull();
    expect($connection->getSchemaBuilder())->toBeInstanceOf(DB2Builder::class);
    expect($connection->getSchemaGrammar())->toBeInstanceOf(DB2SchemaGrammar::class);
});

it('übernimmt date_format aus der Config in die Query-Grammar', function () {
    $connection = makeRecordingConnection(['schema' => 'MYLIB', 'date_format' => 'Y-m-d-H.i.s.u']);

    expect($connection->getQueryGrammar()->getDateFormat())->toBe('Y-m-d-H.i.s.u');
});

it('akzeptiert eine Closure als PDO für Lazy Connections', function () {
    // Laravel übergibt dem Resolver eine Closure, die PDO erst bei Bedarf auflöst.
    // Ein PDO-Typehint würde hier mit einem TypeError abbrechen.
    $connection = new DB2Connection(fn () => null, 'testdb', '', ['schema' => 'MYLIB']);

    expect($connection->getDefaultSchema())->toBe('MYLIB');
});
