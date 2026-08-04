<?php

declare(strict_types=1);

use Beryildiz\DB2Driver\Schema\DB2SchemaGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;

// Hilfsfunktion: Ruft eine geschützte Methode per Reflection auf
function callProtected(object $object, string $method, mixed ...$args): mixed
{
    $ref = new ReflectionMethod($object, $method);
    $ref->setAccessible(true);

    return $ref->invoke($object, ...$args);
}

// Hilfsfunktion: Erstellt einen minimalen Blueprint-Stub
function makeBlueprint(string $table = 'test_table'): Blueprint
{
    return new Blueprint(makeConnection(), $table);
}

it('compileTableExists gibt SQL mit information_schema.tables zurück', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());

    $sql = $grammar->compileTableExists();

    expect($sql)
        ->toContain('information_schema.tables')
        ->toContain('table_schema')
        ->toContain('table_name');
});

it('compileColumnExists gibt SQL mit information_schema.columns zurück', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());

    $sql = $grammar->compileColumnExists();

    expect($sql)
        ->toContain('information_schema.columns')
        ->toContain('column_name');
});

it('typeBoolean-Spalte enthält smallint und check constraint', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());

    $column = new Fluent([
        'type' => 'boolean',
        'prefix' => 'tbl',
        'name' => 'active',
        'default' => null,
    ]);

    $sql = callProtected($grammar, 'typeBoolean', $column);

    expect($sql)
        ->toContain('smallint')
        ->toContain('check(')
        ->toContain('in(0, 1)');
});

it('typeEnum-Spalte enthält die erlaubten Werte', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());

    $column = new Fluent([
        'type' => 'enum',
        'name' => 'status',
        'allowed' => ['active', 'inactive', 'pending'],
    ]);

    $sql = callProtected($grammar, 'typeEnum', $column);

    expect($sql)
        ->toContain('varchar(255)')
        ->toContain('check(')
        ->toContain("'active'")
        ->toContain("'inactive'")
        ->toContain("'pending'")
        ->not->toContain('enum(');
});

it('compileDrop gibt drop table zurück', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $blueprint = makeBlueprint('orders');
    $command = new Fluent;

    $sql = $grammar->compileDrop($blueprint, $command);

    expect($sql)
        ->toContain('drop table')
        ->toContain('orders');
});

it('compileDropIfExists gibt drop table zurück (kein IF EXISTS)', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $blueprint = makeBlueprint('orders');
    $command = new Fluent;

    $sql = $grammar->compileDropIfExists($blueprint, $command);

    expect($sql)
        ->toContain('drop table')
        ->toContain('orders')
        ->not->toContain('if exists');
});

it('compileDropUnique verwendet DROP CONSTRAINT statt DROP INDEX', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $blueprint = makeBlueprint('orders');
    $command = new Fluent(['index' => 'orders_status_unique']);

    $sql = $grammar->compileDropUnique($blueprint, $command);

    expect($sql)
        ->toContain('drop constraint')
        ->not->toContain('drop index');
});

it('compileDropForeign verwendet DROP CONSTRAINT statt DROP FOREIGN KEY', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $blueprint = makeBlueprint('orders');
    $command = new Fluent(['index' => 'orders_user_id_foreign']);

    $sql = $grammar->compileDropForeign($blueprint, $command);

    expect($sql)
        ->toContain('drop constraint')
        ->not->toContain('drop foreign key');
});

it('compileRenameColumn gibt ALTER TABLE ... RENAME COLUMN ... TO ... zurück', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $blueprint = makeBlueprint('orders');
    $command = new Fluent(['from' => 'old_name', 'to' => 'new_name']);

    $sql = $grammar->compileRenameColumn($blueprint, $command);

    expect($sql)
        ->toContain('alter table')
        ->toContain('rename column')
        ->toContain('old_name')
        ->toContain('to')
        ->toContain('new_name');
});

it('compileChange gibt SET DATA TYPE Statement für eine reine Typ-Änderung zurück', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $blueprint = makeBlueprint('orders');
    $column = new Fluent([
        'type' => 'string',
        'name' => 'status',
        'length' => 100,
        'nullable' => true,
        'default' => null,
        'autoIncrement' => false,
        'generated' => null,
        'renameTo' => null,
    ]);
    $command = new Fluent(['column' => $column]);

    $statements = $grammar->compileChange($blueprint, $command);

    expect($statements)->toBeArray();
    expect($statements[0])
        ->toContain('alter table')
        ->toContain('alter column')
        ->toContain('set data type')
        ->toContain('varchar(100)');
    expect($statements[1])->toContain('drop not null');
});

it('compileChange wirft RuntimeException bei autoIncrement-Änderung', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $blueprint = makeBlueprint('orders');
    $column = new Fluent([
        'type' => 'integer',
        'name' => 'id',
        'nullable' => false,
        'default' => null,
        'autoIncrement' => true,
        'generated' => null,
        'renameTo' => null,
    ]);
    $command = new Fluent(['column' => $column]);

    expect(fn () => $grammar->compileChange($blueprint, $command))
        ->toThrow(RuntimeException::class);
});

it('compileDropIndex erzeugt DROP INDEX (kein ALTER TABLE)', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $blueprint = makeBlueprint('orders');
    $command = new Fluent(['index' => 'orders_status_index']);

    $sql = $grammar->compileDropIndex($blueprint, $command);

    expect($sql)
        ->toStartWith('drop index')
        ->not->toContain('alter table');
});

it('compileDropIndex entfernt Schema-Prefix aus Indexnamen bei qualifiziertem Tabellennamen', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $blueprint = makeBlueprint('myschema.orders');
    $command = new Fluent(['index' => 'myschema_orders_status_index']);

    $sql = $grammar->compileDropIndex($blueprint, $command);

    expect($sql)
        ->toStartWith('drop index')
        ->toContain('orders_status_index')
        ->not->toContain('myschema_orders_status_index');
});

it('typeTimestamp nicht-nullable ohne explizites Default enthält genau ein default current_timestamp', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $column = new Fluent(['type' => 'timestamp', 'name' => 'created_at', 'nullable' => false, 'default' => null]);

    $sql = callProtected($grammar, 'typeTimestamp', $column);

    expect($sql)->toBe('timestamp default current_timestamp');
});

it('typeTimestamp mit explizitem Default enthält kein implizites current_timestamp', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $column = new Fluent(['type' => 'timestamp', 'name' => 'created_at', 'nullable' => false, 'default' => '2024-01-01 00:00:00']);

    $sql = callProtected($grammar, 'typeTimestamp', $column);

    expect($sql)->toBe('timestamp');
});

it('typeDate nicht-nullable ohne Default enthält genau ein default current_date', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $column = new Fluent(['type' => 'date', 'name' => 'day', 'nullable' => false, 'default' => null]);

    expect(callProtected($grammar, 'typeDate', $column))->toBe('date default current_date');
});

it('typeDate mit explizitem Default enthält kein implizites current_date', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $column = new Fluent(['type' => 'date', 'name' => 'day', 'nullable' => false, 'default' => '2024-01-01']);

    expect(callProtected($grammar, 'typeDate', $column))->toBe('date');
});

it('typeTime nicht-nullable ohne Default enthält genau ein default current_time', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $column = new Fluent(['type' => 'time', 'name' => 'slot', 'nullable' => false, 'default' => null]);

    expect(callProtected($grammar, 'typeTime', $column))->toBe('time default current_time');
});

it('compileExecuteCommand verdoppelt einfache Anführungszeichen im Kommando genau einmal', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $blueprint = makeBlueprint('orders');
    $command = new Fluent(['command' => "TEST 'value'"]);

    $sql = callProtected($grammar, 'compileExecuteCommand', $blueprint, $command);

    expect($sql)
        ->toBe("CALL QSYS2.QCMDEXC('TEST ''value''')");
});
