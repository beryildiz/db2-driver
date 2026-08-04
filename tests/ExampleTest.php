<?php

declare(strict_types=1);

it('DB2QueryGrammar kann instanziiert werden', function () {
    expect(new \Beryildiz\DB2Driver\DB2QueryGrammar())->toBeInstanceOf(\Beryildiz\DB2Driver\DB2QueryGrammar::class);
});
