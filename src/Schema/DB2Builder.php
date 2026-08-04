<?php

declare(strict_types=1);

namespace Beryildiz\DB2Driver\Schema;

use Beryildiz\DB2Driver\DB2Connection;
use Beryildiz\DB2Driver\DB2Processor;
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
        $sql = $this->db2Grammar()->compileTableExists();
        $schemaTable = explode('.', $table);

        if (count($schemaTable) > 1) {
            $schema = $schemaTable[0];
            $table = $this->db2Connection()->getTablePrefix().$schemaTable[1];
        } else {
            $schema = $this->db2Connection()->getDefaultSchema();
            $table = $this->db2Connection()->getTablePrefix().$table;
        }

        return count($this->db2Connection()->select($sql, [
            $schema,
            $table,
        ])) > 0;
    }

    /**
     * Get the column listing for a given table.
     */
    public function getColumnListing($table): array
    {
        $sql = $this->db2Grammar()->compileColumnExists();
        $schema = $this->db2Connection()->getDefaultSchema();
        $table = $this->db2Connection()->getTablePrefix().$table;

        $tableExploded = explode('.', $table);

        if (count($tableExploded) > 1) {
            $schema = $tableExploded[0];
            $table = $tableExploded[1];
        }

        $results = $this->db2Connection()->select($sql, [
            $schema,
            $table,
        ]);

        $res = $this->db2Processor()->processColumnListing($results);

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
            $this->db2Connection()->setCurrentSchema($schemaTable[0]);
        }

        $blueprint->build();
        $this->db2Connection()->resetCurrentSchema();
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

        if ($this->resolver !== null) {
            /** @var DB2Blueprint */
            return call_user_func($this->resolver, $connection, $table, $callback);
        }

        return new DB2Blueprint($connection, $table, $callback);
    }

    /**
     * Liefert die Connection als DB2Connection.
     * DB2Builder funktioniert nur mit einer DB2Connection — wird diese Invariante verletzt,
     * liegt ein Konfigurationsfehler vor.
     */
    private function db2Connection(): DB2Connection
    {
        if (! $this->connection instanceof DB2Connection) {
            throw new \LogicException(
                'DB2Builder erfordert eine DB2Connection, erhalten: '.get_class($this->connection)
            );
        }

        return $this->connection;
    }

    /**
     * Liefert die Schema-Grammar als DB2SchemaGrammar.
     */
    private function db2Grammar(): DB2SchemaGrammar
    {
        if (! $this->grammar instanceof DB2SchemaGrammar) {
            throw new \LogicException(
                'DB2Builder erfordert eine DB2SchemaGrammar, erhalten: '.get_class($this->grammar)
            );
        }

        return $this->grammar;
    }

    /**
     * Liefert den Processor als DB2Processor.
     */
    private function db2Processor(): DB2Processor
    {
        $processor = $this->connection->getPostProcessor();

        if (! $processor instanceof DB2Processor) {
            throw new \LogicException(
                'DB2Builder erfordert einen DB2Processor, erhalten: '.get_class($processor)
            );
        }

        return $processor;
    }
}
