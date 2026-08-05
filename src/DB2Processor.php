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
            // sonst geht der Table-Prefix verloren.
            $sequenceStr = $query->getGrammar()->columnize($sequence);
        }

        $finalSql = sprintf('select %s from new table (%s)', $sequenceStr, $sql);
        $results = $query->getConnection()
            ->select($finalSql, $values);

        if (is_array($sequence)) {
            return array_values((array) $results[0]);
        }

        // IBM i liefert Spaltennamen je nach PDO::ATTR_CASE in Groß- oder Kleinschreibung —
        // daher case-insensitiver Zugriff statt zweier Einzelabfragen.
        $result = array_change_key_case((array) $results[0], CASE_LOWER);
        $key = strtolower((string) $sequenceStr);

        if (! array_key_exists($key, $result)) {
            throw new \RuntimeException(sprintf(
                'DB2Processor: Spalte "%s" nicht im Ergebnis von INSERT ... FROM NEW TABLE enthalten. Vorhanden: %s',
                $sequenceStr,
                implode(', ', array_keys($result)) ?: '(keine)'
            ));
        }

        $id = $result[$key];

        return is_numeric($id) ? (int) $id : $id;
    }

    /**
     * Process the results of a column listing query.
     *
     * War in Illuminate\Database\Query\Processors\Processor bis 9.x vorhanden und wurde
     * danach entfernt; DB2Builder::getColumnListing() nutzt den Hook weiterhin.
     *
     * @param  array<int, mixed>  $results
     * @return array<int, mixed>
     */
    public function processColumnListing($results): array
    {
        return $results;
    }
}
