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

// ---------------------------------------------------------------------------
// DSN-Escaping (ODBC-Konvention: Werte mit Sonderzeichen in {…}, "}" wird zu "}}")
// ---------------------------------------------------------------------------

function baseDsnConfig(array $overrides = []): array
{
    return array_merge([
        'driverName' => '{IBM i Access ODBC Driver}',
        'host' => 'myserver',
        'port' => 446,
        'database' => 'MYDB',
        'username' => 'user1',
        'password' => 'secret',
    ], $overrides);
}

it('getDsn klammert Werte mit Semikolon und verdoppelt schließende Klammern', function () {
    $dsn = (new DB2Connector)->getDsn(baseDsnConfig(['password' => 'pa;ss}x']));

    expect($dsn)->toContain('Password={pa;ss}}x}');
});

it('getDsn klammert odbc_keywords-Werte mit Semikolon', function () {
    $dsn = (new DB2Connector)->getDsn(baseDsnConfig(['odbc_keywords' => ['DefaultLibraries' => 'A;B']]));

    expect($dsn)->toContain('DefaultLibraries={A;B}');
});

it('getDsn lässt bereits geklammerte Werte unverändert', function () {
    $dsn = (new DB2Connector)->getDsn(baseDsnConfig(['password' => '{abc}']));

    expect($dsn)
        ->toContain('DRIVER={IBM i Access ODBC Driver};')
        ->not->toContain('{{IBM')
        ->toContain('Password={abc}')
        ->not->toContain('Password={{abc}}}');
});

it('getDsn ist für Werte ohne Sonderzeichen identisch zu 1.0.1', function () {
    $dsn = (new DB2Connector)->getDsn(baseDsnConfig(['odbc_keywords' => ['CommitMode' => 2]]));

    expect($dsn)->toBe('odbc:DRIVER={IBM i Access ODBC Driver};System=myserver;Port=446;Database=MYDB;UserID=user1;Password=secret;CommitMode=2');
});

it('getDsn lässt Port weg wenn nicht konfiguriert', function (array $config) {
    $dsn = (new DB2Connector)->getDsn($config);

    expect($dsn)
        ->not->toContain('Port=')
        ->toBe('odbc:DRIVER={IBM i Access ODBC Driver};System=myserver;Database=MYDB;UserID=user1;Password=secret');
})->with([
    'ohne Key' => [array_diff_key(baseDsnConfig(), ['port' => true])],
    'null' => [baseDsnConfig(['port' => null])],
    'leerer String' => [baseDsnConfig(['port' => ''])],
]);

it('getDsn escaped Werte, die nur scheinbar geklammert sind', function (string $password, string $expected) {
    // Nur ein gültig geklammerter Wert ({…} mit intern verdoppelten "}") wird durchgereicht —
    // sonst ließen sich über "{a};Database=x}" weitere Keywords in den DSN schmuggeln.
    $dsn = (new DB2Connector)->getDsn(baseDsnConfig(['password' => $password]));

    expect($dsn)->toEndWith('Password='.$expected);
})->with([
    'schließende Klammer mittendrin' => ['{a}b}', '{{a}}b}}}'],
    'Keyword-Injection' => ['{a};Database=evil}', '{{a}};Database=evil}}}'],
    'gültig geklammert mit }}' => ['{a}}b}', '{a}}b}'],
]);
