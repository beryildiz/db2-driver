<?php

declare(strict_types=1);

use Beryildiz\DB2Driver\Schema\DB2Blueprint;
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

it('compileDropIfExists gibt DROP TABLE IF EXISTS zurück (erfordert IBM i >= 7.2)', function () {
    // "DROP TABLE ... IF EXISTS" gibt es auf IBM i erst ab Release 7.2. Das entspricht dem
    // Verhalten des Original-Pakets und ist bewusst nicht durch eine hasTable()-Vorprüfung
    // ersetzt (die kostete zwei zusätzliche Roundtrips pro Aufruf).
    $grammar = new DB2SchemaGrammar(makeConnection());
    $blueprint = makeBlueprint('orders');
    $command = new Fluent;

    $sql = $grammar->compileDropIfExists($blueprint, $command);

    expect($sql)->toBe('drop table if exists orders');
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

// ---------------------------------------------------------------------------
// Laravel-12-Kernverhalten: ein "add"-Command pro Spalte
// ---------------------------------------------------------------------------

it('compileAdd erzeugt genau ein Statement für die Spalte des Commands', function () {
    // Seit Laravel 11/12 wandelt Blueprint::addImpliedCommands() jede ColumnDefinition in
    // einen eigenen "add"-Command mit ->column um. Würde compileAdd() über alle
    // Blueprint-Spalten iterieren, entstünden N×N Statements ("column already exists").
    $grammar = new DB2SchemaGrammar(makeConnection());
    $blueprint = makeBlueprint('orders');
    $blueprint->string('first_name', 50);
    $blueprint->string('last_name', 50);

    $column = new Fluent([
        'type' => 'string',
        'name' => 'first_name',
        'length' => 50,
        'nullable' => true,
        'default' => null,
    ]);

    $statements = $grammar->compileAdd($blueprint, new Fluent(['column' => $column]));

    expect($statements)->toHaveCount(1);
    expect($statements[0])
        ->toContain('alter table orders add first_name varchar(50)')
        ->not->toContain('last_name');
});

// ---------------------------------------------------------------------------
// Weitere compile*-Kommandos
// ---------------------------------------------------------------------------

it('compileCreate ohne systemName erzeugt einfaches CREATE TABLE', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $blueprint = makeBlueprint('orders');
    $blueprint->integer('id');

    $sql = $grammar->compileCreate($blueprint, new Fluent);

    expect($sql)
        ->toStartWith('create table orders (')
        ->not->toContain('for system name');
});

it('compileCreate mit systemName ergänzt FOR SYSTEM NAME', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $blueprint = new DB2Blueprint(makeConnection(), 'orders');
    $blueprint->forSystemName('ORD');
    $blueprint->integer('id');

    $sql = $grammar->compileCreate($blueprint, new Fluent);

    expect($sql)->toContain('create table orders for system name ORD (');
});

it('compileLabel erzeugt LABEL ON TABLE', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());

    $sql = $grammar->compileLabel(makeBlueprint('orders'), new Fluent(['label' => 'Auftraege']));

    expect($sql)->toBe("label on table orders is 'Auftraege'");
});

it('compileDropPrimary erzeugt DROP PRIMARY KEY', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());

    expect($grammar->compileDropPrimary(makeBlueprint('orders'), new Fluent))
        ->toBe('alter table orders drop primary key');
});

it('compileRename erzeugt RENAME TABLE', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());

    expect($grammar->compileRename(makeBlueprint('orders'), new Fluent(['to' => 'orders_old'])))
        ->toBe('rename table orders to orders_old');
});

it('compileChange ergänzt SET DEFAULT bei explizitem Default', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());
    $column = new Fluent([
        'type' => 'string',
        'name' => 'status',
        'length' => 20,
        'nullable' => false,
        'default' => 'open',
        'autoIncrement' => false,
        'generated' => null,
        'renameTo' => null,
    ]);

    $statements = $grammar->compileChange(makeBlueprint('orders'), new Fluent(['column' => $column]));

    expect($statements)->toHaveCount(3);
    expect($statements[1])->toContain('set not null');
    expect($statements[2])->toBe("alter table orders alter column status set default 'open'");
});

// ---------------------------------------------------------------------------
// getDefaultValue
// ---------------------------------------------------------------------------

it('getDefaultValue normalisiert Booleans zu 0/1', function () {
    // Ein rohes PHP-false würde zu einem leeren DEFAULT interpolieren und ungültiges
    // SQL erzeugen; DB2 for i bildet boolean als SMALLINT ab.
    $grammar = new DB2SchemaGrammar(makeConnection());

    expect(callProtected($grammar, 'getDefaultValue', false))->toBe(0);
    expect(callProtected($grammar, 'getDefaultValue', true))->toBe(1);
});

it('getDefaultValue verdoppelt Apostrophe in String-Defaults', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());

    expect(callProtected($grammar, 'getDefaultValue', "O'Brien"))->toBe("'O''Brien'");
});

it('getDefaultValue lässt Zahlen unangetastet', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());

    expect(callProtected($grammar, 'getDefaultValue', 42))->toBe(42);
    expect(callProtected($grammar, 'getDefaultValue', 1.5))->toBe(1.5);
});

// ---------------------------------------------------------------------------
// Constraint-Namen
// ---------------------------------------------------------------------------

it('modifyIncrement erzeugt bei schema-qualifizierter Tabelle einen punktfreien Constraint-Namen', function () {
    // Constraint-Namen dürfen in DB2 for i keinen Punkt enthalten — mit
    // $blueprint->getTable() entstand vorher "MYLIB.orders_id_primary".
    $grammar = new DB2SchemaGrammar(makeConnection());
    $blueprint = makeBlueprint('MYLIB.orders');
    $column = new Fluent(['type' => 'integer', 'name' => 'id', 'autoIncrement' => true]);

    $sql = callProtected($grammar, 'modifyIncrement', $blueprint, $column);

    expect($sql)
        ->toContain('orders_id_primary')
        ->not->toContain('MYLIB.orders_id_primary');
});

// ---------------------------------------------------------------------------
// Spaltentypen
// ---------------------------------------------------------------------------

it('typeFloat nutzt precision wenn gesetzt', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());

    expect(callProtected($grammar, 'typeFloat', new Fluent(['precision' => 24])))->toBe('float(24)');
});

it('typeFloat ohne precision gibt einfaches float zurück', function () {
    $grammar = new DB2SchemaGrammar(makeConnection());

    expect(callProtected($grammar, 'typeFloat', new Fluent(['precision' => null])))->toBe('float');
});

it('nicht unterstützte Spaltentypen werfen einen Fehler statt falsches SQL zu erzeugen', function (string $type) {
    // json/ulid/tinyInteger etc. sind auf DB2 for i bewusst nicht abgebildet (wie im
    // Original-Paket). Grammar::getType() dispatcht über "type{Name}"; da Grammar das
    // Macroable-Trait nutzt, entsteht eine sprechende BadMethodCallException statt
    // stillschweigend falschem SQL.
    $grammar = new DB2SchemaGrammar(makeConnection());

    expect(fn () => callProtected($grammar, 'getType', new Fluent(['type' => $type])))
        ->toThrow(BadMethodCallException::class);
})->with(['json', 'jsonb', 'ulid', 'tinyInteger', 'mediumInteger', 'tinyText', 'year', 'ipAddress', 'macAddress']);
