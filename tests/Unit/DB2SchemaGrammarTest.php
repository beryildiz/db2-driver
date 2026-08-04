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
    return new Blueprint($table);
}

it('compileTableExists gibt SQL mit information_schema.tables zurück', function () {
    $grammar = new DB2SchemaGrammar();

    $sql = $grammar->compileTableExists();

    expect($sql)
        ->toContain('information_schema.tables')
        ->toContain('table_schema')
        ->toContain('table_name');
});

it('compileColumnExists gibt SQL mit information_schema.columns zurück', function () {
    $grammar = new DB2SchemaGrammar();

    $sql = $grammar->compileColumnExists();

    expect($sql)
        ->toContain('information_schema.columns')
        ->toContain('column_name');
});

it('typeBoolean-Spalte enthält smallint und check constraint', function () {
    $grammar = new DB2SchemaGrammar();

    $column = new Fluent([
        'type'    => 'boolean',
        'prefix'  => 'tbl',
        'name'    => 'active',
        'default' => null,
    ]);

    $sql = callProtected($grammar, 'typeBoolean', $column);

    expect($sql)
        ->toContain('smallint')
        ->toContain('check(')
        ->toContain('in(0, 1)');
});

it('typeEnum-Spalte enthält die erlaubten Werte', function () {
    $grammar = new DB2SchemaGrammar();

    $column = new Fluent([
        'type'    => 'enum',
        'name'    => 'status',
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
    $grammar = new DB2SchemaGrammar();
    $blueprint = makeBlueprint('orders');
    $command = new Fluent();

    $sql = $grammar->compileDrop($blueprint, $command);

    expect($sql)
        ->toContain('drop table')
        ->toContain('orders');
});

it('compileDropIfExists gibt drop table zurück (kein IF EXISTS)', function () {
    $grammar = new DB2SchemaGrammar();
    $blueprint = makeBlueprint('orders');
    $command = new Fluent();

    $sql = $grammar->compileDropIfExists($blueprint, $command);

    expect($sql)
        ->toContain('drop table')
        ->toContain('orders')
        ->not->toContain('if exists');
});

it('compileDropUnique verwendet DROP CONSTRAINT statt DROP INDEX', function () {
    $grammar = new DB2SchemaGrammar();
    $blueprint = makeBlueprint('orders');
    $command = new Fluent(['index' => 'orders_status_unique']);

    $sql = $grammar->compileDropUnique($blueprint, $command);

    expect($sql)
        ->toContain('drop constraint')
        ->not->toContain('drop index');
});

it('compileDropForeign verwendet DROP CONSTRAINT statt DROP FOREIGN KEY', function () {
    $grammar = new DB2SchemaGrammar();
    $blueprint = makeBlueprint('orders');
    $command = new Fluent(['index' => 'orders_user_id_foreign']);

    $sql = $grammar->compileDropForeign($blueprint, $command);

    expect($sql)
        ->toContain('drop constraint')
        ->not->toContain('drop foreign key');
});
