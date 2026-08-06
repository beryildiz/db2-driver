<?php

declare(strict_types=1);

use Beryildiz\DB2Driver\DB2Connection;
use Beryildiz\DB2Driver\DB2Processor;
use Illuminate\Database\Query\Builder as QueryBuilder;

it('processColumnListing gibt das Input-Array unverändert zurück', function () {
    $processor = new DB2Processor;

    $input = [
        ['column_name' => 'id'],
        ['column_name' => 'name'],
        ['column_name' => 'email'],
    ];

    expect($processor->processColumnListing($input))->toBe($input);
});

/**
 * Query-Builder auf einer Connection, deren select() ein festes Ergebnis liefert.
 *
 * @param  list<mixed>  $selectResult
 */
function makeRecordingQueryBuilder(array $selectResult, string $prefix = ''): QueryBuilder
{
    $connection = new class(fn () => null, 'testdb', $prefix, []) extends DB2Connection
    {
        /** @var list<mixed> */
        public array $selectResult = [];

        public ?string $capturedSql = null;

        public function select($query, $bindings = [], $useReadPdo = true): array
        {
            $this->capturedSql = $query;

            return $this->selectResult;
        }
    };

    $connection->selectResult = $selectResult;

    return $connection->table('employees');
}

it('processInsertGetId liest die ID case-insensitiv aus dem Ergebnis', function () {
    // IBM i liefert Spaltennamen je nach PDO::ATTR_CASE in Groß- oder Kleinschreibung.
    $row = new stdClass;
    $row->ID = '42';

    $id = (new DB2Processor)->processInsertGetId(
        makeRecordingQueryBuilder([$row]),
        'insert into employees (name) values (?)',
        ['Ada']
    );

    expect($id)->toBe(42);
});

it('processInsertGetId akzeptiert lowercase Schlüssel', function () {
    $row = new stdClass;
    $row->id = '7';

    expect((new DB2Processor)->processInsertGetId(makeRecordingQueryBuilder([$row]), 'insert ...', []))
        ->toBe(7);
});

it('processInsertGetId gibt nicht-numerische IDs unverändert zurück', function () {
    $row = new stdClass;
    $row->ID = 'ABC-1';

    expect((new DB2Processor)->processInsertGetId(makeRecordingQueryBuilder([$row]), 'insert ...', []))
        ->toBe('ABC-1');
});

it('processInsertGetId nutzt die übergebene Sequence-Spalte', function () {
    $row = new stdClass;
    $row->EMPLOYEE_NO = '9';

    expect((new DB2Processor)->processInsertGetId(makeRecordingQueryBuilder([$row]), 'insert ...', [], 'employee_no'))
        ->toBe(9);
});

it('processInsertGetId wirft eine sprechende Exception wenn die Spalte fehlt', function () {
    // Ohne den Guard wäre das eine "Undefined array key"-Warnung mit null als ID.
    $row = new stdClass;
    $row->SOMETHING_ELSE = '1';

    expect(fn () => (new DB2Processor)->processInsertGetId(makeRecordingQueryBuilder([$row]), 'insert ...', []))
        ->toThrow(RuntimeException::class, 'nicht im Ergebnis');
});

it('processInsertGetId gibt bei Array-Sequence alle Werte zurück', function () {
    $row = new stdClass;
    $row->ID = '1';
    $row->TENANT_ID = '2';

    expect((new DB2Processor)->processInsertGetId(makeRecordingQueryBuilder([$row]), 'insert ...', [], ['id', 'tenant_id']))
        ->toBe(['1', '2']);
});

// ---------------------------------------------------------------------------
// processSelect — Pagination / Aggregate-Case-Fix
// ---------------------------------------------------------------------------

it('processSelect gibt normale SELECT-Ergebnisse unverändert zurück', function () {
    $processor = new DB2Processor;
    $builder = makeRecordingQueryBuilder([]);

    $row = new stdClass;
    $row->FORM8 = 'A';
    $row->ARTDE = 'Widget';

    $results = $processor->processSelect($builder, [$row]);

    // Kein Aggregat gesetzt → Original-Objekte unverändert durchgereicht.
    expect($results[0])->toBe($row)
        ->and($results[0]->FORM8)->toBe('A');
});

it('processSelect normalisiert Aggregate-Spaltennamen auf Kleinschreibung', function () {
    $processor = new DB2Processor;

    // IBM i liefert AGGREGATE statt aggregate.
    $row = new stdClass;
    $row->AGGREGATE = '42';

    // $query->aggregate muss gesetzt sein (wie bei count(), sum() etc.).
    $builder = makeRecordingQueryBuilder([$row]);
    $builder->aggregate = ['function' => 'count', 'columns' => ['*']];

    $results = $processor->processSelect($builder, [$row]);

    // Builder::getCountForPagination() greift via ->aggregate zu.
    expect($results[0]->aggregate)->toBe('42')
        ->and(isset($results[0]->AGGREGATE))->toBeFalse();
});

it('processSelect normalisiert auch lowercase Aggregate-Spaltennamen (kein Doppel-Key)', function () {
    $processor = new DB2Processor;

    $row = new stdClass;
    $row->aggregate = '7';

    $builder = makeRecordingQueryBuilder([$row]);
    $builder->aggregate = ['function' => 'count', 'columns' => ['*']];

    $results = $processor->processSelect($builder, [$row]);

    expect($results[0]->aggregate)->toBe('7');
});

it('processSelect lässt Nicht-Objekte in Aggregate-Ergebnissen unberührt', function () {
    $processor = new DB2Processor;

    $builder = makeRecordingQueryBuilder([]);
    $builder->aggregate = ['function' => 'count', 'columns' => ['*']];

    $results = $processor->processSelect($builder, [['AGGREGATE' => '3']]);

    // Array-Zeilen werden nicht transformiert (Builder-Fallback greift selbst).
    expect($results[0])->toBe(['AGGREGATE' => '3']);
});

it('processInsertGetId umhüllt das INSERT mit SELECT FROM NEW TABLE', function () {
    $row = new stdClass;
    $row->ID = '1';

    $builder = makeRecordingQueryBuilder([$row]);
    (new DB2Processor)->processInsertGetId($builder, 'insert into employees (name) values (?)', ['Ada']);

    $connection = $builder->getConnection();
    expect($connection->capturedSql)
        ->toBe('select id from new table (insert into employees (name) values (?))');
});
