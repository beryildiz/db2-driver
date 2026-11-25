<?php

declare(strict_types=1);

use Beryildiz\DB2Driver\DB2Connection;
use Beryildiz\DB2Driver\DB2QueryGrammar;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;

// Hilfsfunktion: Erstellt eine testbare Subklasse mit öffentlichen Wrappern für geschützte Methoden
function makeTestableGrammar(): DB2QueryGrammar
{
    return new class(makeConnection()) extends DB2QueryGrammar
    {
        public function compileLimitPublic(Builder $query, int $limit): string
        {
            return $this->compileLimit($query, $limit);
        }

        public function compileOffsetPublic(Builder $query, int $offset): string
        {
            return $this->compileOffset($query, $offset);
        }
    };
}

// Hilfsfunktion: Erstellt einen minimalen Builder-Stub ohne echte DB-Verbindung
function makeQueryBuilder(): Builder
{
    $connection = Mockery::mock(ConnectionInterface::class);
    $processor = Mockery::mock(Processor::class);

    return new Builder($connection, new DB2QueryGrammar(makeConnection()), $processor);
}

afterEach(function () {
    Mockery::close();
});

it('compileLimit gibt FETCH FIRST X ROWS ONLY im Compatibility-Mode zurück', function () {
    $grammar = makeTestableGrammar();
    $builder = makeQueryBuilder();

    $sql = $grammar->compileLimitPublic($builder, 10);

    expect($sql)->toBe('FETCH FIRST 10 ROWS ONLY');
});

it('compileLimit delegiert an parent wenn Compatibility-Mode deaktiviert', function () {
    $grammar = makeTestableGrammar();
    $grammar->setOffsetCompatibilityMode(false);
    $builder = makeQueryBuilder();

    $sql = $grammar->compileLimitPublic($builder, 5);

    // Parent-Implementierung erzeugt "limit 5"
    expect($sql)->toBe('limit 5');
});

it('compileOffset gibt leeren String im Compatibility-Mode zurück', function () {
    $grammar = makeTestableGrammar();
    $builder = makeQueryBuilder();

    $sql = $grammar->compileOffsetPublic($builder, 20);

    expect($sql)->toBe('');
});

it('compileSavepoint gibt korrekte DB2-Syntax zurück', function () {
    $grammar = new DB2QueryGrammar(makeConnection());

    $sql = $grammar->compileSavepoint('sp_test');

    expect($sql)->toBe('SAVEPOINT sp_test ON ROLLBACK RETAIN CURSORS');
});

it('getDateFormat gibt parent-Default zurück wenn kein Format gesetzt', function () {
    $grammar = new DB2QueryGrammar(makeConnection());

    // Parent-Default ist 'Y-m-d H:i:s'
    expect($grammar->getDateFormat())->toBe('Y-m-d H:i:s');
});

it('getDateFormat gibt gesetztes Format zurück nach setDateFormat', function () {
    $grammar = new DB2QueryGrammar(makeConnection());
    $grammar->setDateFormat('Y-m-d');

    expect($grammar->getDateFormat())->toBe('Y-m-d');
});

it('setOffsetCompatibilityMode(false) schaltet FETCH FIRST ab', function () {
    $grammar = makeTestableGrammar();
    $grammar->setOffsetCompatibilityMode(false);
    $builder = makeQueryBuilder();

    $sql = $grammar->compileLimitPublic($builder, 3);

    // Im normalen Modus gibt parent "limit 3" zurück
    expect($sql)->not->toContain('FETCH FIRST');
    expect($sql)->toContain('limit');
});

it('compileExists nutzt einen gequoteten, kleingeschriebenen exists-Alias', function () {
    // EXISTS ist in Db2 for i ein reserviertes Wort; Laravels Builder::exists() liest
    // $results['exists'] — der delimited Alias kommt unabhängig von PDO::ATTR_CASE klein zurück.
    $grammar = new DB2QueryGrammar(makeConnection());
    $builder = makeQueryBuilder();
    $builder->from('users');

    $sql = $grammar->compileExists($builder);

    expect($sql)
        ->toContain('select 1 as "exists" from users')
        ->not->toContain('1 exists')
        ->toContain('FETCH FIRST 1 ROWS ONLY');
});

// Hilfsfunktion: Erstellt eine Grammar, deren Connection einen Table-Prefix trägt.
// compileAnsiOffset() liest den Prefix über wrapTable() aus $this->connection —
// nicht aus der (gemockten) Builder-Connection.
function makeGrammarWithPrefix(string $prefix = ''): DB2QueryGrammar
{
    $connection = new DB2Connection(fn () => null, 'testdb', $prefix, []);
    $connection->useDefaultSchemaGrammar();

    return new DB2QueryGrammar($connection);
}

it('compileSelect mit offset baut ANSI-Offset-Query ohne Prefix', function () {
    $grammar = makeGrammarWithPrefix();
    $builder = makeQueryBuilder();
    $builder->from('employees')->offset(10);
    $builder->grammar = $grammar;

    $sql = $grammar->compileSelect($builder);

    expect($sql)->toContain('select employees.*,  row_number() over (order by 1) as row_num');
    expect($sql)->toContain('where row_num >= 11');
});

it('compileSelect mit offset behält Table-Prefix bei', function () {
    $grammar = makeGrammarWithPrefix('pfx_');
    $builder = makeQueryBuilder();
    $builder->from('employees')->offset(10);
    $builder->grammar = $grammar;

    $sql = $grammar->compileSelect($builder);

    expect($sql)->toContain('select pfx_employees.*');
});

it('compileSelect mit offset setzt Prefix bei schema-qualifizierter Tabelle auf das letzte Segment (Regression)', function () {
    $grammar = makeGrammarWithPrefix('pfx_');
    $builder = makeQueryBuilder();
    $builder->from('MYLIB.employees')->offset(10);
    $builder->grammar = $grammar;

    $sql = $grammar->compileSelect($builder);

    expect($sql)->toContain('select MYLIB.pfx_employees.*');
    expect($sql)->not->toContain('pfx_MYLIB.employees');
});

it('compileSelect mit offset ohne orderBy fügt Dummy "order by 1" ein', function () {
    $grammar = makeGrammarWithPrefix();
    $builder = makeQueryBuilder();
    $builder->from('employees')->offset(5);
    $builder->grammar = $grammar;

    $sql = $grammar->compileSelect($builder);

    expect($sql)->toContain('over (order by 1)');
});

it('compileSelect mit offset und limit erzeugt "between"-Constraint statt FETCH FIRST', function () {
    $grammar = makeGrammarWithPrefix();
    $builder = makeQueryBuilder();
    $builder->from('employees')->offset(10)->limit(5);
    $builder->grammar = $grammar;

    $sql = $grammar->compileSelect($builder);

    expect($sql)->toContain('where row_num between 11 and 15');
    expect($sql)->not->toContain('FETCH FIRST');
});

it('compileSelect mit offset und expliziten Spalten überschreibt Spalten nicht mit *', function () {
    $grammar = makeGrammarWithPrefix();
    $builder = makeQueryBuilder();
    $builder->from('employees')->select(['id', 'name'])->offset(10);
    $builder->grammar = $grammar;

    $sql = $grammar->compileSelect($builder);

    // wrapValue() der DB2-Grammar fügt keine Anführungszeichen hinzu.
    expect($sql)->toContain('select id, name,  row_number()');
});
