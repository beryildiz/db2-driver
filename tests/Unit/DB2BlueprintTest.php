<?php

declare(strict_types=1);

use Beryildiz\DB2Driver\Schema\DB2Blueprint;

it('indexCommand mit explizitem Namen erzeugt create index mit diesem Namen', function () {
    $blueprint = new DB2Blueprint(makeConnection(), 'orders');
    $blueprint->index(['status'], 'my_custom_idx');

    $commands = $blueprint->getCommands();
    $indexCmd = collect($commands)->first(fn ($c) => $c->name === 'index');

    expect($indexCmd)->not->toBeNull();
    expect($indexCmd->index)->toBe('my_custom_idx');
});

it('indexCommand ohne Namen generiert Konventionsname', function () {
    $blueprint = new DB2Blueprint(makeConnection(), 'orders');
    $blueprint->index(['status']);

    $commands = $blueprint->getCommands();
    $indexCmd = collect($commands)->first(fn ($c) => $c->name === 'index');

    expect($indexCmd->index)->toContain('orders');
    expect($indexCmd->index)->toContain('status');
});

it('synchro setzt den Indexnamen als Fluent-Attribut auf der id_sync-Spalte', function () {
    // synchro() ruft ->index($name) auf der ColumnDefinition auf; addFluentIndexes() (in toSql())
    // liest diesen Wert und leitet ihn an indexCommand() weiter.
    $blueprint = new DB2Blueprint(makeConnection(), 'employees');
    $blueprint->synchro('idx_sync_emp');

    $idSyncColumn = collect($blueprint->getColumns())->first(fn ($c) => $c->name === 'id_sync');

    // Das Fluent-Attribut 'index' enthält den übergebenen Namen (nicht true)
    expect($idSyncColumn)->not->toBeNull();
    expect($idSyncColumn->index)->toBe('idx_sync_emp');
});

it('dropSynchro erzeugt einen dropIndex-Command mit dem übergebenen Namen', function () {
    $blueprint = new DB2Blueprint(makeConnection(), 'employees');
    $blueprint->dropSynchro('idx_sync_emp');

    $dropCmd = collect($blueprint->getCommands())->first(fn ($c) => $c->name === 'dropIndex');

    expect($dropCmd)->not->toBeNull();
    expect($dropCmd->index)->toBe('idx_sync_emp');
});
