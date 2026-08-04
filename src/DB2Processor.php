<?php

declare(strict_types=1);

namespace Beryildiz\DB2Driver;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;

class DB2Processor extends Processor
{
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null): int|string|array
    {
        $sequenceStr = $sequence ?: 'id';

        if (is_array($sequence)) {
            // Grammar der Query nutzen statt einer neuen, unkonfigurierten Instanz —
            // sonst geht der Table-Prefix verloren (siehe DB2QueryGrammar::getTablePrefix()).
            $sequenceStr = $query->getGrammar()->columnize($sequence);
        }

        $finalSql = sprintf('select %s from new table (%s)', $sequenceStr, $sql);
        $results = $query->getConnection()
            ->select($finalSql, $values);

        if (is_array($sequence)) {
            return array_values((array) $results[0]);
        }

        $result = (array) $results[0];
        $id = $result[$sequenceStr] ?? $result[strtoupper($sequenceStr)];

        return is_numeric($id) ? (int) $id : $id;
    }

    /**
     * Process the results of a column listing query.
     * This was present in Illuminate\Database\Query\Processor.php 9.x but later removed.
     */
    public function processColumnListing($results): array
    {
        return $results;
    }
}
