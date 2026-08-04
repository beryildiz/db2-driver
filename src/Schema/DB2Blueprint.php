<?php

declare(strict_types=1);

namespace Beryildiz\DB2Driver\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Fluent;

class DB2Blueprint extends Blueprint
{
    /**
     * The sequence number of reply list entries.
     */
    private int $replyListSequenceNumber;

    /**
     * The system name for the table (IBM i short name, max 10 chars).
     */
    public string $systemName = '';

    /**
     * Get the sequence number of reply list entries.
     *
     * @return int
     */
    public function getReplyListSequenceNumber()
    {
        return $this->replyListSequenceNumber;
    }

    public function setReplyListSequenceNumber(int $replyListSequenceNumber): void
    {
        $this->replyListSequenceNumber = $replyListSequenceNumber;
    }

    /**
     * Get the raw SQL statements for the blueprint.
     *
     * Seit Laravel 12 ist toSql() parameterlos — Blueprint hält Connection/Grammar bereits
     * intern (per Konstruktor), sie müssen nicht mehr durchgereicht werden.
     */
    public function toSql(): array
    {
        $this->addReplyListEntryCommands();

        return parent::toSql();
    }

    /**
     * Add the commands that are necessary to DROP and Rename statements on IBMi.
     */
    protected function addReplyListEntryCommands(): void
    {
        if ($this->commandsNamed(['dropColumn', 'renameColumn'])->count() > 0) {
            array_unshift($this->commands, $this->createCommand('addReplyListEntry'), $this->createCommand('changeJob'));
            array_push($this->commands, $this->createCommand('removeReplyListEntry'));
        }
    }

    /**
     * Specify a system name for the table.
     */
    public function forSystemName(string $systemName): void
    {
        $this->systemName = $systemName;
    }

    /**
     * Specify a label for the table.
     */
    public function label(string $label): Fluent
    {
        return $this->addCommand('label', compact('label'));
    }

    /**
     * Add a new index command to the blueprint.
     *
     * Laravel 12 hat den Elternparameter $operatorClass (Postgres-Operatorklassen) hinzugefügt —
     * ohne ihn hier ist die Signatur inkompatibel (Fatal Error beim Laden der Klasse).
     *
     * Für Typ 'index': explizit übergebenen Namen respektieren (nur generieren, wenn null) und
     * indexSystem-Flag anhängen — ohne diesen Zweig würde ein explizites
     * $table->index($cols, 'my_idx') stumm einen anderen Namen erzeugen, was
     * synchro()/dropSynchro() inkonsistent macht.
     *
     * @param  string|array  $columns
     * @return Fluent
     */
    protected function indexCommand($type, $columns, $index, $algorithm = null, $operatorClass = null)
    {
        $columns = (array) $columns;

        if ($type === 'index') {
            $indexSystem = false;

            if (is_null($index)) {
                $index = $this->createIndexName($type, $columns);
            }

            return $this->addCommand($type, compact('index', 'indexSystem', 'columns'));
        }

        // If no name was specified for this index, we will create one using a basic
        // convention of the table name, followed by the columns, followed by an
        // index type, such as primary or index, which makes the index unique.
        if (is_null($index)) {
            $index = $this->createIndexName($type, $columns);
        }

        return $this->addCommand($type, compact('index', 'columns'));
    }

    /**
     * Create a new boolean column on the table.
     *
     * @return ColumnDefinition
     */
    public function boolean($column)
    {
        $prefix = $this->table;
        // Schema name is not needed in the check constraint prefix for boolean type
        $schemaTable = explode('.', $this->table);

        if (count($schemaTable) > 1) {
            $prefix = $schemaTable[1];
        }

        return $this->addColumn('boolean', $column, ['prefix' => $prefix]);
    }

    /**
     * Create a new numeric column on the table.
     */
    public function numeric(string $column, int $total = 8, int $places = 2): Fluent
    {
        return $this->addColumn('numeric', $column, compact('total', 'places'));
    }

    /**
     * Add synchronization columns (id_sync, hashcode, optional data_master) to the table.
     */
    public function synchro(string $index, bool $masterizable = false): void
    {
        $this->string('id_sync', 20)
            ->index($index);
        $this->string('hashcode', 32);

        if ($masterizable === true) {
            $this->boolean('data_master')
                ->default(true);
        }
    }

    /**
     * Drop synchronization columns (id_sync, hashcode) and their index from the table.
     */
    public function dropSynchro(string $index): void
    {
        $this->dropColumn('id_sync', 'hashcode');
        $this->dropIndex($index);
    }
}
