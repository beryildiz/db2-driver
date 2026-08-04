<?php

declare(strict_types=1);

use Beryildiz\DB2Driver\DB2Connection;
use Beryildiz\DB2Driver\DB2Processor;
use Beryildiz\DB2Driver\Schema\DB2Builder;

/**
 * Erstellt eine DB2Connection mit einer Fake-select()-Implementierung.
 *
 * @param  list<object>  $selectResult
 */
function makeBuilderWithFakeSelect(array $selectResult): DB2Builder
{
    $pdo = new class extends PDO
    {
        public function __construct() {}
    };

    $connection = $this->getMockBuilder(DB2Connection::class)
        ->setConstructorArgs([$pdo, 'testdb', '', ['schema' => 'MYLIB']])
        ->onlyMethods(['select', 'getTablePrefix', 'getDefaultSchema'])
        ->getMock();

    $connection->method('getTablePrefix')->willReturn('');
    $connection->method('getDefaultSchema')->willReturn('MYLIB');
    $connection->method('select')->willReturn($selectResult);
    $connection->useDefaultSchemaGrammar();

    return new DB2Builder($connection);
}

it('getColumnListing verarbeitet uppercase Spaltennamen von IBM i korrekt', function () {
    // IBM i liefert Spaltennamen als Großbuchstaben (COLUMN_NAME statt column_name)
    $row = new stdClass;
    $row->COLUMN_NAME = 'FIRST_NAME';

    $pdo = new class extends PDO
    {
        public function __construct() {}
    };

    $connection = $this->getMockBuilder(DB2Connection::class)
        ->setConstructorArgs([$pdo, 'testdb', '', ['schema' => 'MYLIB']])
        ->onlyMethods(['select', 'getTablePrefix', 'getDefaultSchema', 'getPostProcessor'])
        ->getMock();

    $connection->method('getTablePrefix')->willReturn('');
    $connection->method('getDefaultSchema')->willReturn('MYLIB');
    $connection->method('select')->willReturn([$row]);
    $connection->method('getPostProcessor')->willReturn(new DB2Processor);
    $connection->useDefaultSchemaGrammar();

    $builder = new DB2Builder($connection);
    $columns = $builder->getColumnListing('employees');

    expect($columns)->toBe(['FIRST_NAME']);
});

it('getColumnListing verarbeitet auch lowercase Spaltennamen', function () {
    $row = new stdClass;
    $row->column_name = 'first_name';

    $pdo = new class extends PDO
    {
        public function __construct() {}
    };

    $connection = $this->getMockBuilder(DB2Connection::class)
        ->setConstructorArgs([$pdo, 'testdb', '', ['schema' => 'MYLIB']])
        ->onlyMethods(['select', 'getTablePrefix', 'getDefaultSchema', 'getPostProcessor'])
        ->getMock();

    $connection->method('getTablePrefix')->willReturn('');
    $connection->method('getDefaultSchema')->willReturn('MYLIB');
    $connection->method('select')->willReturn([$row]);
    $connection->method('getPostProcessor')->willReturn(new DB2Processor);
    $connection->useDefaultSchemaGrammar();

    $builder = new DB2Builder($connection);
    $columns = $builder->getColumnListing('employees');

    expect($columns)->toBe(['first_name']);
});
