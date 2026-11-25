<?php

declare(strict_types=1);

use Beryildiz\DB2Driver\Schema\DB2Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Fluent;

function makeDB2Blueprint(string $table = 'test_table'): DB2Blueprint
{
    return new DB2Blueprint(makeConnection(), $table);
}

/**
 * Liefert die Commands eines Blueprints als Fluent-Liste.
 *
 * @return list<Fluent>
 */
function blueprintCommands(DB2Blueprint $blueprint): array
{
    return array_values(array_filter(
        $blueprint->getCommands(),
        fn ($command) => ! $command instanceof ColumnDefinition
    ));
}

it('indexCommand mit explizitem Namen erzeugt create index mit diesem Namen', function () {
    // Das Original verwarf den übergebenen Namen bedingungslos.
    $blueprint = makeDB2Blueprint('orders');
    $blueprint->index(['status'], 'my_custom_idx');

    $command = blueprintCommands($blueprint)[0];

    expect($command->name)->toBe('index');
    expect($command->index)->toBe('my_custom_idx');
});

it('indexCommand ohne Namen generiert Konventionsname', function () {
    $blueprint = makeDB2Blueprint('orders');
    $blueprint->index(['status']);

    expect(blueprintCommands($blueprint)[0]->index)->toBe('orders_status_index');
});

it('indexCommand setzt indexSystem auf false', function () {
    // Aufsatzpunkt für IBM i "for system name"; im Original ungenutzt und bewusst so belassen.
    $blueprint = makeDB2Blueprint('orders');
    $blueprint->index(['status']);

    expect(blueprintCommands($blueprint)[0]->indexSystem)->toBeFalse();
});

it('indexCommand respektiert Namen auch für unique', function () {
    $blueprint = makeDB2Blueprint('orders');
    $blueprint->unique(['email'], 'my_unique');

    $command = blueprintCommands($blueprint)[0];

    expect($command->name)->toBe('unique');
    expect($command->index)->toBe('my_unique');
});

it('synchro setzt den Indexnamen als Fluent-Attribut auf der id_sync-Spalte', function () {
    $blueprint = makeDB2Blueprint('orders');
    $blueprint->synchro('sync_idx');

    $columns = $blueprint->getColumns();

    expect($columns[0]->name)->toBe('id_sync');
    expect($columns[0]->index)->toBe('sync_idx');
    expect($columns[1]->name)->toBe('hashcode');
});

it('synchro und dropSynchro verwenden denselben Indexnamen', function () {
    // Regression: Solange indexCommand() den übergebenen Namen verwarf, erzeugte synchro()
    // einen Konventionsnamen, dropSynchro() aber den übergebenen -> DROP INDEX schlug fehl.
    $create = makeDB2Blueprint('orders');
    $create->synchro('sync_idx');
    $create->toSql();

    $indexNames = array_map(
        fn ($c) => $c->index,
        array_values(array_filter(blueprintCommands($create), fn ($c) => $c->name === 'index'))
    );

    $drop = makeDB2Blueprint('orders');
    $drop->dropSynchro('sync_idx');

    $dropNames = array_map(
        fn ($c) => $c->index,
        array_values(array_filter(blueprintCommands($drop), fn ($c) => $c->name === 'dropIndex'))
    );

    expect($indexNames)->toBe(['sync_idx']);
    expect($dropNames)->toBe(['sync_idx']);
});

it('dropSynchro erzeugt einen dropIndex-Command mit dem übergebenen Namen', function () {
    $blueprint = makeDB2Blueprint('orders');
    $blueprint->dropSynchro('sync_idx');

    $names = array_map(fn ($c) => $c->name, blueprintCommands($blueprint));

    expect($names)->toContain('dropColumn');
    expect($names)->toContain('dropIndex');
});

it('synchro mit masterizable ergänzt eine data_master-Spalte', function () {
    $blueprint = makeDB2Blueprint('orders');
    $blueprint->synchro('sync_idx', masterizable: true);

    $columns = $blueprint->getColumns();

    expect($columns[2]->name)->toBe('data_master');
    expect($columns[2]->type)->toBe('boolean');
    expect($columns[2]->default)->toBeTrue();
});

it('forSystemName setzt den IBM i Kurznamen der Tabelle', function () {
    $blueprint = makeDB2Blueprint('orders');
    $blueprint->forSystemName('ORD');

    expect($blueprint->systemName)->toBe('ORD');
});

it('label erzeugt einen label-Command', function () {
    $blueprint = makeDB2Blueprint('orders');
    $blueprint->label('Auftraege');

    $command = blueprintCommands($blueprint)[0];

    expect($command->name)->toBe('label');
    expect($command->label)->toBe('Auftraege');
});

it('numeric erzeugt eine Spalte mit total und places', function () {
    $blueprint = makeDB2Blueprint('orders');
    $blueprint->numeric('amount', 10, 3);

    $column = $blueprint->getColumns()[0];

    expect($column->type)->toBe('numeric');
    expect($column->total)->toBe(10);
    expect($column->places)->toBe(3);
});

it('boolean nutzt bei schema-qualifizierter Tabelle nur den Tabellennamen als Prefix', function () {
    $blueprint = makeDB2Blueprint('MYLIB.orders');
    $blueprint->boolean('active');

    expect($blueprint->getColumns()[0]->prefix)->toBe('orders');
});

it('toSql ergänzt Reply-List-Commands bei dropColumn', function () {
    // IBM i stellt bei DROP COLUMN eine Inquiry-Message (CPA32B2), die vorab automatisch
    // beantwortet werden muss.
    $blueprint = makeDB2Blueprint('orders');
    $blueprint->dropColumn('obsolete');

    $names = array_map(fn ($c) => $c->name, blueprintCommands($blueprint));
    expect($names)->not->toContain('addReplyListEntry');

    // Die Commands werden erst beim Kompilieren ergänzt; ohne Connection-Query kann
    // toSql() hier nicht durchlaufen, daher nur die Command-Liste prüfen.
    expect($names)->toContain('dropColumn');
});

it('getReplyListSequenceNumber wirft ohne vorherige Zuweisung eine sprechende Exception', function () {
    // Die Property ist typisiert; ohne Guard wäre das ein nichtssagender Fatal Error.
    expect(fn () => makeDB2Blueprint('orders')->getReplyListSequenceNumber())
        ->toThrow(LogicException::class, 'Reply-List-Sequenznummer');
});

it('setReplyListSequenceNumber und getReplyListSequenceNumber sind symmetrisch', function () {
    $blueprint = makeDB2Blueprint('orders');
    $blueprint->setReplyListSequenceNumber(4711);

    expect($blueprint->getReplyListSequenceNumber())->toBe(4711);
});

it('enum setzt den unqualifizierten Tabellennamen als prefix', function () {
    $blueprint = makeDB2Blueprint('MYLIB.orders');
    $blueprint->enum('status', ['a', 'b']);

    $column = $blueprint->getColumns()[0];

    expect($column->prefix)->toBe('orders')
        ->and($column->allowed)->toBe(['a', 'b']);
});
