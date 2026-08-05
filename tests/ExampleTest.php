<?php

declare(strict_types=1);
use Beryildiz\DB2Driver\DB2QueryGrammar;

it('DB2QueryGrammar kann instanziiert werden', function () {
    expect(new DB2QueryGrammar(makeConnection()))->toBeInstanceOf(DB2QueryGrammar::class);
});
