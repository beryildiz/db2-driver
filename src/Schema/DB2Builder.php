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
        [$schema, $table] = $this->parseQualifiedTable($table);

        return count($this->db2Connection()->select($this->db2Grammar()->compileTableExists(), [
            $schema,
            $table,
        ])) > 0;
    }

    /**
     * Get the column listing for a given table.
     */
    public function getColumnListing($table): array
    {
        [$schema, $table] = $this->parseQualifiedTable($table);

        $results = $this->db2Connection()->select($this->db2Grammar()->compileColumnExists(), [
            $schema,
            $table,
        ]);

        $res = $this->db2Processor()->processColumnListing($results);

        return array_values(array_map(function ($r) {
            // IBM i liefert Spaltennamen je nach PDO::ATTR_CASE in Groß- oder
            // Kleinschreibung — daher case-insensitiver Zugriff.
            $lower = array_change_key_case((array) $r, CASE_LOWER);

            return trim((string) $lower['column_name']);
        }, $res));
    }

    /**
     * Zerlegt eine (optional schema-qualifizierte) Tabellenreferenz in Schema und
     * gepräfixten Tabellennamen.
     *
     * Wichtig: Der Table-Prefix wird erst NACH der Schema-Trennung angewendet —
     * andernfalls landete der Prefix vor dem Schemanamen ("pfx_MYLIB.tab").
     * Ohne explizites Schema greift das Connection-Default-Schema.
     *
     * @return array{string, string}
     */
    private function parseQualifiedTable(string $table): array
    {
        // parseSchemaAndTable() der Basisklasse liefert zusätzlich den Guard gegen
        // dreiteilige Referenzen ("db.schema.table" -> InvalidArgumentException).
        [$schema, $table] = $this->parseSchemaAndTable($table);

        return [
            $schema ?? $this->db2Connection()->getDefaultSchema(),
            $this->db2Connection()->getTablePrefix().$table,
        ];
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
        $schemaSwitched = count($schemaTable) > 1;

        if ($schemaSwitched) {
            $this->db2Connection()->setCurrentSchema($schemaTable[0]);
        }

        try {
            $blueprint->build();
        } finally {
            // Ohne finally bliebe das Connection-Schema nach einer fehlgeschlagenen
            // Migration dauerhaft verstellt und würde alle Folgequeries verfälschen.
            if ($schemaSwitched) {
                $this->db2Connection()->resetCurrentSchema();
            }
        }
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
