<?php

declare(strict_types=1);

use Beryildiz\DB2Driver\DB2Connector;

it('getDsn gibt korrekten DSN-String mit Basis-Config zurück', function () {
    $connector = new DB2Connector;

    $config = [
        'driverName' => '{IBM i Access ODBC Driver}',
        'host' => 'myserver',
        'port' => 446,
        'database' => 'MYDB',
        'username' => 'user1',
        'password' => 'secret',
    ];

    $dsn = $connector->getDsn($config);

    expect($dsn)
        ->toContain('DRIVER={IBM i Access ODBC Driver}')
        ->toContain('System=myserver')
        ->toContain('Port=446')
        ->toContain('Database=MYDB')
        ->toContain('UserID=user1')
        ->toContain('Password=secret');
});

it('getDsn fügt odbc_keywords korrekt an', function () {
    $connector = new DB2Connector;

    $config = [
        'driverName' => '{IBM i Access ODBC Driver}',
        'host' => 'myserver',
        'port' => 446,
        'database' => 'MYDB',
        'username' => 'user1',
        'password' => 'secret',
        'odbc_keywords' => [
            'CommitMode' => 2,
            'NAM' => 1,
        ],
    ];

    $dsn = $connector->getDsn($config);

    expect($dsn)
        ->toContain('CommitMode=2')
        ->toContain('NAM=1');
});

it('normalizeSchema gibt Schema-Namen in Großbuchstaben zurück', function () {
    $connector = new DB2Connector;

    $ref = new ReflectionMethod($connector, 'normalizeSchema');
    $ref->setAccessible(true);

    expect($ref->invoke($connector, 'mylib'))->toBe('MYLIB');
    expect($ref->invoke($connector, 'MY_SCHEMA'))->toBe('MY_SCHEMA');
});

it('normalizeSchema wirft InvalidArgumentException bei ungültigem Schema-Namen', function () {
    $connector = new DB2Connector;

    $ref = new ReflectionMethod($connector, 'normalizeSchema');
    $ref->setAccessible(true);

    expect(fn () => $ref->invoke($connector, 'foo; DROP TABLE users'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $ref->invoke($connector, "foo'bar"))->toThrow(InvalidArgumentException::class);
});

it('getDsn ohne odbc_keywords enthält keine zusätzlichen Segmente', function () {
    $connector = new DB2Connector;

    $config = [
        'driverName' => '{IBM i Access ODBC Driver}',
        'host' => 'myserver',
        'port' => 446,
        'database' => 'MYDB',
        'username' => 'user1',
        'password' => 'secret',
    ];

    $dsn = $connector->getDsn($config);

    // Genau 6 Segmente durch Semikolon getrennt
    $segments = explode(';', $dsn);
    expect($segments)->toHaveCount(6);
});
