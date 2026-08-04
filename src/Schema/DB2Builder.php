<?php

declare(strict_types=1);

namespace Beryildiz\DB2Driver\Schema;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

class DB2Builder extends Builder
{
    /**
     * Determine if the given table exists.
     */
    public function hasTable($table): bool
    {
        $sql = $this->grammar->compileTableExists();
        $schemaTable = explode('.', $table);

        if (count($schemaTable) > 1) {
            $schema = $schemaTable[0];
            $table = $this->connection->getTablePrefix().$schemaTable[1];
        } else {
            $schema = $this->connection->getDefaultSchema();
            $table = $this->connection->getTablePrefix().$table;
        }

        return count($this->connection->select($sql, [
            $schema,
            $table,
        ])) > 0;
    }

    /**
     * Get the column listing for a given table.
     */
    public function getColumnListing($table): array
    {
        $sql = $this->grammar->compileColumnExists();
        $schema = $this->connection->getDefaultSchema();
        $table = $this->connection->getTablePrefix().$table;

        $tableExploded = explode('.', $table);

        if (count($tableExploded) > 1) {
            $schema = $tableExploded[0];
            $table = $tableExploded[1];
        }

        $results = $this->connection->select($sql, [
            $schema,
            $table,
        ]);

        $res = $this->connection->getPostProcessor()
            ->processColumnListing($results);

        return array_values(array_map(function ($r) {
            // IBM i liefert Spaltennamen in Großbuchstaben — case-insensitiver Zugriff,
            // identisch zum Muster in DB2Processor::processInsertGetId().
            $row = (array) $r;
            $lower = array_change_key_case($row, CASE_LOWER);

            return $lower['column_name'];
        }, $res));
    }

    /**
     * Execute the blueprint to build / modify the table.
     *
     * Seit Laravel 12 ist Blueprint::build() parameterlos — Connection/Grammar sind bereits
     * im Blueprint-Konstruktor gesetzt.
     */
    protected function build(Blueprint $blueprint)
    {
        $schemaTable = explode('.', $blueprint->getTable());

        if (count($schemaTable) > 1) {
            $this->connection->setCurrentSchema($schemaTable[0]);
        }

        $blueprint->build();
        $this->connection->resetCurrentSchema();
    }

    /**
     * Create a new command set with a Closure.
     *
     * Seit Laravel 12 erhält der Resolver die Connection als ersten Parameter
     * (siehe Illuminate\Database\Schema\Builder::createBlueprint()).
     *
     * @return DB2Blueprint
     */
    protected function createBlueprint($table, ?Closure $callback = null)
    {
        $connection = $this->connection;

        if (isset($this->resolver)) {
            /** @var DB2Blueprint */
            return call_user_func($this->resolver, $connection, $table, $callback);
        }

        return new DB2Blueprint($connection, $table, $callback);
    }
}
