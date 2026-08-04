<?php

declare(strict_types=1);

use Beryildiz\DB2Driver\DB2Processor;

it('processColumnListing gibt das Input-Array unverändert zurück', function () {
    $processor = new DB2Processor;

    $input = [
        ['column_name' => 'id'],
        ['column_name' => 'name'],
        ['column_name' => 'email'],
    ];

    $result = $processor->processColumnListing($input);

    expect($result)->toBe($input);
});
