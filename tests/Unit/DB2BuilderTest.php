<?php

declare(strict_types=1);

use Beryildiz\DB2Driver\DB2Connection;
use Beryildiz\DB2Driver\Schema\DB2Builder;

/**
 * DB2Connection, die select() ohne echte Datenbank beantwortet und die abgesetzte
 * Query samt Bindings mitschreibt. Bewusst eine Subklasse statt eines Mock-Frameworks:
 * mockery ist nur transitiv installiert und nicht in composer.json deklariert.
 */
final class RecordingDB2Connection extends DB2Connection
{
    /** @var list<mixed> */
    public array $selectResult = [];

    public ?string $capturedSql = null;

    /** @var array<int, mixed> */
    public array $capturedBindings = [];

    public function select($query, $bindings = [], $useReadPdo = true): array
    {
        $this->capturedSql = $query;
        $this->capturedBindings = $bindings;

        return $this->selectResult;
    }
}

/**
 * @param  list<mixed>  $selectResult
 */
function makeBuilder(array $selectResult, string $schema = 'MYLIB', string $prefix = ''): DB2Builder
{
    $connection = new RecordingDB2Connection(fn () => null, 'testdb', $prefix, ['schema' => $schema]);
    $connection->selectResult = $selectResult;
    $connection->useDefaultSchemaGrammar();

    return new DB2Builder($connection);
}

/**
 * Liefert die Connection des Builders für Binding-Assertions.
 */
function recordingConnection(DB2Builder $builder): RecordingDB2Connection
{
    $connection = $builder->getConnection();

    expect($connection)->toBeInstanceOf(RecordingDB2Connection::class);

    return $connection;
}

// ---------------------------------------------------------------------------
// hasTable
// ---------------------------------------------------------------------------

it('hasTable übergibt Default-Schema und gepräfixten Tabellennamen als Bindings', function () {
    $builder = makeBuilder([new stdClass], schema: 'MYLIB', prefix: 'pfx_');

    expect($builder->hasTable('employees'))->toBeTrue();

    $connection = recordingConnection($builder);
    expect($connection->capturedBindings)->toBe(['MYLIB', 'pfx_employees']);
    expect($connection->capturedSql)->toContain('information_schema.tables');
});

it('hasTable trennt ein explizites Schema ab und präfixt nur den Tabellennamen', function () {
    // Regression: Der Prefix darf nicht vor dem Schemanamen landen ("pfx_OTHERLIB.employees").
    $builder = makeBuilder([new stdClass], schema: 'MYLIB', prefix: 'pfx_');

    $builder->hasTable('OTHERLIB.employees');

    expect(recordingConnection($builder)->capturedBindings)->toBe(['OTHERLIB', 'pfx_employees']);
});

it('hasTable gibt false zurück wenn keine Zeile gefunden wird', function () {
    expect(makeBuilder([])->hasTable('employees'))->toBeFalse();
});

it('hasTable lehnt dreiteilige Referenzen ab', function () {
    expect(fn () => makeBuilder([new stdClass])->hasTable('db.schema.table'))
        ->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// getColumnListing
// ---------------------------------------------------------------------------

it('getColumnListing verarbeitet uppercase Spaltennamen von IBM i korrekt', function () {
    // IBM i liefert Spaltennamen je nach PDO::ATTR_CASE als COLUMN_NAME statt column_name.
    $row = new stdClass;
    $row->COLUMN_NAME = 'FIRST_NAME';

    expect(makeBuilder([$row])->getColumnListing('employees'))->toBe(['FIRST_NAME']);
});

it('getColumnListing verarbeitet auch lowercase Spaltennamen', function () {
    $row = new stdClass;
    $row->column_name = 'first_name';

    expect(makeBuilder([$row])->getColumnListing('employees'))->toBe(['first_name']);
});

it('getColumnListing entfernt CHAR-Padding aus Spaltennamen', function () {
    $row = new stdClass;
    $row->COLUMN_NAME = 'FIRST_NAME   ';

    expect(makeBuilder([$row])->getColumnListing('employees'))->toBe(['FIRST_NAME']);
});

it('getColumnListing nutzt das Default-Schema als Binding, nicht den Datenbanknamen', function () {
    // Das Original nutzte hier getDatabaseName() und war damit inkonsistent zu hasTable().
    $row = new stdClass;
    $row->COLUMN_NAME = 'ID';

    $builder = makeBuilder([$row], schema: 'MYLIB');
    $builder->getColumnListing('employees');

    $connection = recordingConnection($builder);
    expect($connection->capturedBindings)->toBe(['MYLIB', 'employees']);
    expect($connection->capturedSql)->toContain('information_schema.columns');
});

// ---------------------------------------------------------------------------
// Bewusst nicht unterstützt
// ---------------------------------------------------------------------------

it('dropAllTables ist nicht unterstützt und schlägt laut fehl', function () {
    // Bewusste Entscheidung: DB2 for i hat keinen globalen FK-Schalter und "DROP TABLE
    // CASCADE" existiert nicht. Ein stillschweigend halb funktionierendes db:wipe wäre
    // gefährlicher als eine klare Fehlermeldung.
    expect(fn () => makeBuilder([])->dropAllTables())->toThrow(LogicException::class);
});

it('dropAllViews ist nicht unterstützt und schlägt laut fehl', function () {
    expect(fn () => makeBuilder([])->dropAllViews())->toThrow(LogicException::class);
});

it('Introspection wird nicht unterstützt und schlägt laut fehl', function (string $method) {
    // Die Basisklasse liefert für alle Introspection-Methoden eine sprechende
    // RuntimeException, solange die Grammar sie nicht implementiert.
    expect(fn () => makeBuilder([])->{$method}())->toThrow(RuntimeException::class);
})->with(['getTables', 'getViews', 'getTypes', 'getSchemas']);
