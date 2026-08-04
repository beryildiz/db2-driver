<?php

declare(strict_types=1);

use Beryildiz\DB2Driver\DB2QueryGrammar;
use Illuminate\Database\Query\Builder;

// Hilfsfunktion: Erstellt eine testbare Subklasse mit öffentlichen Wrappern für geschützte Methoden
function makeTestableGrammar(): DB2QueryGrammar
{
    return new class extends DB2QueryGrammar {
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
    $connection = Mockery::mock(\Illuminate\Database\ConnectionInterface::class);
    $processor = Mockery::mock(\Illuminate\Database\Query\Processors\Processor::class);

    return new Builder($connection, new DB2QueryGrammar(), $processor);
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
    $grammar = new DB2QueryGrammar();

    $sql = $grammar->compileSavepoint('sp_test');

    expect($sql)->toBe('SAVEPOINT sp_test ON ROLLBACK RETAIN CURSORS');
});

it('getDateFormat gibt parent-Default zurück wenn kein Format gesetzt', function () {
    $grammar = new DB2QueryGrammar();

    // Parent-Default ist 'Y-m-d H:i:s'
    expect($grammar->getDateFormat())->toBe('Y-m-d H:i:s');
});

it('getDateFormat gibt gesetztes Format zurück nach setDateFormat', function () {
    $grammar = new DB2QueryGrammar();
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

it('compileExists erzeugt valides SQL mit 1 exists und FETCH FIRST 1 ROWS ONLY', function () {
    $grammar = new DB2QueryGrammar();
    $builder = makeQueryBuilder();
    $builder->from('users');

    $sql = $grammar->compileExists($builder);

    expect($sql)->toContain('1 exists');
    expect($sql)->toContain('FETCH FIRST 1 ROWS ONLY');
});
