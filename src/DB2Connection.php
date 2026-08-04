<?php

declare(strict_types=1);

namespace Beryildiz\DB2Driver;

use Beryildiz\DB2Driver\Schema\DB2Builder;
use Beryildiz\DB2Driver\Schema\DB2SchemaGrammar;
use Illuminate\Database\Connection;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use PDO;

class DB2Connection extends Connection
{
    /**
     * The name of the default schema.
     */
    protected $defaultSchema;

    /**
     * The name of the current schema in use.
     */
    protected $currentSchema;

    public function __construct(
        PDO|\Closure $pdo,
        string $database = '',
        string $tablePrefix = '',
        array $config = []
    ) {
        parent::__construct($pdo, $database, $tablePrefix, $config);
        $this->currentSchema = $this->defaultSchema = strtoupper($config['schema'] ?? '');
    }

    /**
     * Get the name of the default schema.
     */
    public function getDefaultSchema(): string
    {
        return $this->defaultSchema;
    }

    /**
     * Reset to default the current schema.
     */
    public function resetCurrentSchema(): void
    {
        $this->setCurrentSchema($this->getDefaultSchema());
    }

    /**
     * Set the name of the current schema.
     */
    public function setCurrentSchema(string $schema): void
    {
        // "DEFAULT" ist ein SQL-Schlüsselwort und darf NICHT als Parameter-Binding gesendet werden,
        // sonst setzt DB2 das Schema auf ein Schema, das wörtlich "DEFAULT" heißt.
        if ($schema === '') {
            $this->statement('SET SCHEMA DEFAULT');

            return;
        }

        $this->statement('SET SCHEMA ?', [strtoupper($schema)]);
    }

    /**
     * Execute a system command on IBMi.
     */
    public function executeCommand(string $command): void
    {
        $this->statement('CALL QSYS2.QCMDEXC(?)', [$command]);
    }

    /**
     * Get a schema builder instance for the connection.
     */
    public function getSchemaBuilder(): DB2Builder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new DB2Builder($this);
    }

    protected function getDefaultQueryGrammar(): Grammar
    {
        // Seit Laravel 12 nimmt Grammar die Connection im Konstruktor entgegen (liefert u.a.
        // den Table-Prefix selbst über $connection->getTablePrefix()) — Connection::withTablePrefix()
        // existiert nicht mehr.
        $defaultGrammar = new DB2QueryGrammar($this);

        // If a date format was specified in constructor
        if (array_key_exists('date_format', $this->config)) {
            $defaultGrammar->setDateFormat($this->config['date_format']);
        }

        // If offset compatibility mode was specified in constructor
        if (array_key_exists('offset_compatibility_mode', $this->config)) {
            $defaultGrammar->setOffsetCompatibilityMode($this->config['offset_compatibility_mode']);
        }

        return $defaultGrammar;
    }

    /**
     * Get the default grammar for specified Schema
     */
    protected function getDefaultSchemaGrammar(): Grammar
    {
        return new DB2SchemaGrammar($this);
    }

    /**
     * Get the default post processor instance
     */
    protected function getDefaultPostProcessor(): Processor
    {
        return new DB2Processor;
    }
}
