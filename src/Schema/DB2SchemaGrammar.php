<?php

declare(strict_types=1);

namespace Beryildiz\DB2Driver\Schema;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;

class DB2SchemaGrammar extends Grammar
{
    /**
     * The possible column modifiers.
     *
     * @var array
     */
    protected $preModifiers = ['ForColumn'];

    protected $modifiers = [
        'Nullable',
        'Default',
        'Generated',
        'Increment',
        'StartWith',
        'Before',
        'ImplicitlyHidden',
    ];

    /**
     * The possible column serials
     *
     * @var array
     */
    protected $serials = [
        'smallInteger',
        'integer',
        'bigInteger',
    ];

    /** @param string $value */
    protected function wrapValue($value): string
    {
        if ($value === '*') {
            return $value;
        }

        return str_replace('"', '""', $value);
    }

    /**
     * Compile the query to determine the list of tables.
     *
     * Seit Laravel 12 deklariert Illuminate\Database\Schema\Grammars\Grammar diese Methode
     * bereits mit der Signatur compileTableExists($schema, $table) (2 Pflichtparameter) —
     * ohne kompatible Signatur wäre das ein Fatal Error ("Declaration must be compatible").
     * $schema/$table werden hier bewusst nicht in die SQL eingesetzt (Bindings statt Literal),
     * DB2Builder::hasTable() übergibt sie weiterhin separat als Parameter-Bindings.
     *
     * @return string
     */
    public function compileTableExists($schema = null, $table = null)
    {
        return 'select * from information_schema.tables where table_schema = upper(?) and table_name = upper(?)';
    }

    /**
     * Compile the query to determine the list of columns.
     *
     * @return string
     */
    public function compileColumnExists()
    {
        return 'select column_name from information_schema.columns where table_schema = upper(?) and table_name = upper(?)';
    }

    /**
     * Compile a create table command.
     *
     * Seit Laravel 12 reicht Blueprint::toSql() die Connection nicht mehr an compile*()-Methoden
     * durch — die Grammar hält bereits ihre eigene Connection (siehe Konstruktor der Elternklasse).
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command): string
    {
        $columns = implode(', ', $this->getColumns($blueprint));
        $sql = 'create table '.$this->wrapTable($blueprint);

        if ($blueprint instanceof DB2Blueprint && $blueprint->systemName !== '') {
            $sql .= ' for system name '.$blueprint->systemName;
        }

        $sql .= " ($columns)";

        return $sql;
    }

    /**
     * Compile a label command.
     */
    public function compileLabel(Blueprint $blueprint, Fluent $command): string
    {
        return 'label on table '.$this->wrapTable($blueprint).' is \''.$command->label.'\'';
    }

    /**
     * Compile the blueprint's column definitions.
     */
    protected function getColumns(Blueprint $blueprint): array
    {
        $columns = [];

        foreach ($blueprint->getColumns() as $column) {
            // Each of the column types have their own compiler functions which are tasked
            // with turning the column definition into its SQL format for this platform
            // used by the connection. The column's modifiers are compiled and added.
            $sql = $this->addPreModifiers($this->wrap($column), $blueprint, $column);
            $sql .= ' '.$this->getType($column);

            $columns[] = $this->addModifiers($sql, $blueprint, $column);
        }

        return $columns;
    }

    /**
     * Add the pre-column modifiers to the definition.
     */
    protected function addPreModifiers(string $sql, Blueprint $blueprint, Fluent $column): string
    {
        foreach ($this->preModifiers as $preModifier) {
            if (method_exists($this, $method = "modify{$preModifier}")) {
                $sql .= $this->{$method}($blueprint, $column);
            }
        }

        return $sql;
    }

    /**
     * Compile an add column command.
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): array
    {
        $table = $this->wrapTable($blueprint);
        $columns = $this->prefixArray('add', $this->getColumns($blueprint));
        $statements = [];

        foreach ($columns as $column) {
            $statements[] = 'alter table '.$table.' '.$column;
        }

        return $statements;
    }

    /**
     * Compile a primary key command.
     */
    public function compilePrimary(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);
        $columns = $this->columnize($command->columns);

        // Schema name is not needed in the constraint name
        $schemaTable = explode('.', $table);

        if (count($schemaTable) > 1) {
            $command->index = str_replace($schemaTable[0].'_', '', $command->index);
        }

        return "alter table {$table} add constraint {$command->index} primary key ({$columns})";
    }

    /**
     * Compile a foreign key command.
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);
        $on = $this->wrapTable($command->on);

        // We need to prepare several of the elements of the foreign key definition
        // before we can create the SQL, such as wrapping the tables and convert
        // an array of columns to comma-delimited strings for the SQL queries.
        $columns = $this->columnize($command->columns);
        $onColumns = $this->columnize((array) $command->references);

        // Schema name is not needed in the constraint name
        $schemaTable = explode('.', $table);

        if (count($schemaTable) > 1) {
            $command->index = str_replace($schemaTable[0].'_', '', $command->index);
        }

        $sql = "alter table {$table} add constraint {$command->index} ";
        $sql .= "foreign key ({$columns}) references {$on} ({$onColumns})";

        // Once we have the basic foreign key creation statement constructed we can
        // build out the syntax for what should happen on an update or delete of
        // the affected columns, which will get something like "cascade", etc.
        if (! is_null($command->onDelete)) {
            $sql .= " on delete {$command->onDelete}";
        }

        if (! is_null($command->onUpdate)) {
            $sql .= " on update {$command->onUpdate}";
        }

        return $sql;
    }

    /**
     * Compile a unique key command.
     */
    public function compileUnique(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);
        $columns = $this->columnize($command->columns);

        // Schema name is not needed in the constraint name
        $schemaTable = explode('.', $table);

        if (count($schemaTable) > 1) {
            $command->index = str_replace($schemaTable[0].'_', '', $command->index);
        }

        return "alter table {$table} add constraint {$command->index} unique({$columns})";
    }

    /**
     * Compile a plain index key command.
     */
    public function compileIndex(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);
        $columns = $this->columnize($command->columns);

        // Schema name is not needed in the constraint name
        $schemaTable = explode('.', $table);

        if (count($schemaTable) > 1) {
            $command->index = str_replace($schemaTable[0].'_', '', $command->index);
        }

        $sql = "create index {$command->index}";

        if ($command->indexSystem) {
            $sql .= " for system name {$command->indexSystem}";
        }

        $sql .= " on {$table}($columns)";

        return $sql;
    }

    /**
     * Compile a drop table command.
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table '.$this->wrapTable($blueprint);
    }

    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table '.$this->wrapTable($blueprint);
    }

    /**
     * Compile a drop column command.
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command): string
    {
        $columns = $this->prefixArray('drop', $this->wrapArray($command->columns));
        $table = $this->wrapTable($blueprint);

        return 'alter table '.$table.' '.implode(', ', $columns);
    }

    /**
     * Compile a rename column command.
     *
     * Seit dem Wegfall von Doctrine DBAL (Laravel 11+) muss jede Schema-Grammar diese
     * Methode selbst implementieren, sonst bleibt `$table->renameColumn()` wirkungslos
     * (Illuminate\Database\Schema\Grammars\Grammar::compileRenameColumn() liefert eine
     * generische, hier nicht passende ANSI-Variante). IBM i/DB2-Syntax:
     * ALTER TABLE "TABLE" RENAME COLUMN "OLD" TO "NEW".
     */
    public function compileRenameColumn(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'alter table %s rename column %s to %s',
            $this->wrapTable($blueprint),
            $this->wrap($command->from),
            $this->wrap($command->to)
        );
    }

    /**
     * Compile a change column command into a series of SQL statements.
     *
     * Seit dem Wegfall von Doctrine DBAL (Laravel 11+) muss jede Schema-Grammar diese
     * Methode selbst implementieren, sonst bleibt `$table->string('x')->change()`
     * wirkungslos (die Basisklasse wirft standardmäßig eine RuntimeException).
     *
     * IBM i/DB2 kennt kein einzelnes "ALTER COLUMN ... <typ> <modifier>" wie MySQL,
     * sondern mehrere eigenständige Klauseln:
     * ALTER TABLE "T" ALTER COLUMN "C" SET DATA TYPE <typ>
     * ALTER TABLE "T" ALTER COLUMN "C" SET NOT NULL | DROP NOT NULL
     * ALTER TABLE "T" ALTER COLUMN "C" SET DEFAULT <wert>
     *
     * Da DB2SchemaGrammar (noch) keine Introspection des alten Spaltenzustands anbietet
     * (siehe V5-Befund), wird — wie bei anderen Treibern auch — die neue Spaltendefinition
     * vollständig ausgeführt statt gegen den alten Zustand zu diffen. Für Kombinationen,
     * die eine reine ALTER-COLUMN-Klausel nicht abdecken kann (auto-increment/generated
     * setzen, Umbenennen im selben Befehl), wird bewusst eine sprechende Exception
     * geworfen statt stillschweigend falsches SQL zu erzeugen.
     *
     * @return list<string>
     */
    public function compileChange(Blueprint $blueprint, Fluent $command): array
    {
        $column = $command->column;

        if ($column->autoIncrement || ! is_null($column->generated) || ! is_null($column->renameTo)) {
            throw new \RuntimeException(
                'DB2SchemaGrammar: Ändern von autoIncrement/generated-Spalten oder Umbenennen über change() wird nicht unterstützt. '.
                'Für strukturelle Änderungen renameColumn() bzw. eine neue Migration nutzen.'
            );
        }

        $table = $this->wrapTable($blueprint);
        $wrappedColumn = $this->wrap($column);

        $statements = [
            "alter table {$table} alter column {$wrappedColumn} set data type ".$this->getType($column),
            sprintf('alter table %s alter column %s %s not null', $table, $wrappedColumn, $column->nullable ? 'drop' : 'set'),
        ];

        if (! is_null($column->default)) {
            $statements[] = "alter table {$table} alter column {$wrappedColumn} set default ".$this->getDefaultValue($column->default);
        }

        return $statements;
    }

    /**
     * Compile a drop primary key command.
     */
    public function compileDropPrimary(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint).' drop primary key';
    }

    public function compileDropUnique(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);

        // Schema name is not needed in the constraint name
        $schemaTable = explode('.', $table);

        if (count($schemaTable) > 1) {
            $command->index = str_replace($schemaTable[0].'_', '', $command->index);
        }

        return "alter table {$table} drop constraint {$command->index}";
    }

    /**
     * Compile a drop index command.
     */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);

        // Schema name is not needed in the constraint name
        $schemaTable = explode('.', $table);

        if (count($schemaTable) > 1) {
            $command->index = str_replace($schemaTable[0].'_', '', $command->index);
        }

        return "alter table {$table} drop index {$command->index}";
    }

    public function compileDropForeign(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);

        // Schema name is not needed in the constraint name
        $schemaTable = explode('.', $table);

        if (count($schemaTable) > 1) {
            $command->index = str_replace($schemaTable[0].'_', '', $command->index);
        }

        return "alter table {$table} drop constraint {$command->index}";
    }

    /**
     * Compile a rename table command.
     */
    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        $from = $this->wrapTable($blueprint);

        return "rename table {$from} to ".$this->wrapTable($command->to);
    }

    /**
     * Create the column definition for a char type.
     */
    protected function typeChar(Fluent $column): string
    {
        return "char({$column->length})";
    }

    /**
     * Create the column definition for a string type.
     */
    protected function typeString(Fluent $column): string
    {
        return "varchar({$column->length})";
    }

    /**
     * Create the column definition for a text type.
     */
    protected function typeText(Fluent $column): string
    {
        return 'clob(64K)';
    }

    /**
     * Create the column definition for a medium text type.
     */
    protected function typeMediumText(Fluent $column): string
    {
        $colLength = ($column->length ? $column->length : 16000);

        return "varchar($colLength)";
    }

    /**
     * Create the column definition for a long text type.
     */
    protected function typeLongText(Fluent $column): string
    {
        $colLength = ($column->length ? $column->length : 16000);

        return "varchar($colLength)";
    }

    /**
     * Create the column definition for a big integer type.
     */
    protected function typeBigInteger(Fluent $column): string
    {
        return 'bigint';
    }

    /**
     * Create the column definition for a integer type.
     */
    protected function typeInteger(Fluent $column): string
    {
        return 'int';
    }

    /**
     * Create the column definition for a small integer type.
     */
    protected function typeSmallInteger(Fluent $column): string
    {
        return 'smallint';
    }

    /**
     * Create the column definition for a numeric type.
     */
    protected function typeNumeric(Fluent $column): string
    {
        return "numeric({$column->total}, {$column->places})";
    }

    /**
     * Create the column definition for a float type.
     */
    protected function typeFloat(Fluent $column): string
    {
        return "decimal({$column->total}, {$column->places})";
    }

    /**
     * Create the column definition for a double type.
     */
    protected function typeDouble(Fluent $column): string
    {
        if ($column->total && $column->places) {
            return "double({$column->total}, {$column->places})";
        }

        return 'double';
    }

    /**
     * Create the column definition for a decimal type.
     */
    protected function typeDecimal(Fluent $column): string
    {
        return "decimal({$column->total}, {$column->places})";
    }

    /**
     * Create the column definition for a boolean type.
     */
    protected function typeBoolean(Fluent $column): string
    {
        $definition = 'smallint constraint %s_%s_%s check(%s in(0, 1)) %s';

        return sprintf($definition, $column->type, $column->prefix, $column->name, $column->name, is_null($column->default) ? ' default 0' : '');
    }

    protected function typeEnum(Fluent $column): string
    {
        $allowed = array_map(fn (string $v): string => "'{$v}'", $column->allowed);

        return 'varchar(255) constraint '.$column->name.'_enum check('.$column->name.' in ('.implode(', ', $allowed).'))';
    }

    /**
     * Create the column definition for a date type.
     */
    protected function typeDate(Fluent $column): string
    {
        if (! $column->nullable) {
            return 'date default current_date';
        }

        return 'date';
    }

    /**
     * Create the column definition for a date-time type.
     */
    protected function typeDateTime(Fluent $column): string
    {
        return $this->typeTimestamp($column);
    }

    /**
     * Create the column definition for a time type.
     */
    protected function typeTime(Fluent $column): string
    {
        if (! $column->nullable) {
            return 'time default current_time';
        }

        return 'time';
    }

    /**
     * Create the column definition for a timestamp type.
     */
    protected function typeTimestamp(Fluent $column): string
    {
        if (! $column->nullable) {
            return 'timestamp default current_timestamp';
        }

        return 'timestamp';
    }

    /**
     * Create the column definition for a binary type.
     */
    protected function typeBinary(Fluent $column): string
    {
        return 'blob';
    }

    /**
     * Create the column definition for a UUID type
     */
    protected function typeUuid(Fluent $column): string
    {
        return 'char(36)';
    }

    /**
     * Get the SQL for a nullable column modifier.
     */
    protected function modifyNullable(Blueprint $blueprint, Fluent $column): ?string
    {
        return $column->nullable ? '' : ' not null';
    }

    /**
     * Get the SQL for a default column modifier.
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->default)) {
            return ' default '.$this->getDefaultValue($column->default);
        }

        return null;
    }

    /**
     * Get the SQL for an auto-increment column modifier.
     */
    protected function modifyIncrement(Blueprint $blueprint, Fluent $column): ?string
    {
        if (in_array($column->type, $this->serials) && $column->autoIncrement) {
            return ' generated by default as identity constraint '.$blueprint->getTable().'_'.$column->name.'_primary primary key';
        }

        return null;
    }

    /**
     * Get the SQL for an "before" column modifier.
     */
    protected function modifyBefore(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->before)) {
            return ' before '.$this->wrap($column->before);
        }

        return null;
    }

    /**
     * Get the SQL for an "for column" column modifier.
     */
    protected function modifyForColumn(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->forColumn)) {
            return ' for column '.$this->wrap($column->forColumn);
        }

        return null;
    }

    /**
     * Get the SQL for a "generated" column modifier.
     */
    protected function modifyGenerated(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->generated)) {
            return ' generated '.($column->generated === true ? 'always' : $this->wrap($column->generated));
        }

        return null;
    }

    /**
     * Get the SQL for a "startWith" column modifier.
     */
    protected function modifyStartWith(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->startWith)) {
            return ' (start with '.$column->startWith.')';
        }

        return null;
    }

    /**
     * Get the SQL for an "implicitly hidden" column modifier.
     */
    protected function modifyImplicitlyHidden(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->implicitlyHidden)) {
            return ' implicitly hidden';
        }

        return null;
    }

    /**
     * Format a value so that it can be used in "default" clauses.
     */
    protected function getDefaultValue(mixed $value): string|int|float|bool|Expression
    {
        if ($value instanceof Expression || is_bool($value) || is_numeric($value)) {
            return $value;
        }

        return "'".strval($value)."'";
    }

    /**
     * Compile a executeCommand command.
     */
    private function compileExecuteCommand(Blueprint $blueprint, Fluent $command): string
    {
        // Zentrale Escaping-Stelle: einfache Anführungszeichen im Kommando verdoppeln,
        // damit das SQL-String-Literal korrekt terminiert wird. Aufrufer dürfen NICHT
        // selbst vor-escapen (sonst doppeltes Escaping, siehe compileAddReplyListEntry).
        $escaped = str_replace("'", "''", $command->command);

        return "CALL QSYS2.QCMDEXC('{$escaped}')";
    }

    /**
     * Compile an addReplyListEntry command.
     *
     * Seit Laravel 12 wird die Connection nicht mehr durchgereicht — die Grammar hat über
     * $this->connection (Illuminate\Database\Grammar) bereits Zugriff auf ihre Connection.
     */
    public function compileAddReplyListEntry(Blueprint $blueprint, Fluent $command): string
    {
        $sequenceNumberQuery = <<<'EOT'
            with reply_list_info(sequence_number) as (
                values(1)
                union all
                select sequence_number + 1
                from reply_list_info
                where sequence_number + 1 between 2 and 9999
            )
            select min(sequence_number) sequence_number
            from reply_list_info
            where not exists (
                select 1
                from qsys2.reply_list_info rli
                where rli.sequence_number = reply_list_info.sequence_number
            )
EOT;

        $blueprint->setReplyListSequenceNumber($sequenceNumber = $this->connection->selectOne($sequenceNumberQuery)->sequence_number);
        // Nicht selbst vor-escapen ("RPY('I')" statt "RPY(''I'')") — das Escaping der
        // einfachen Anführungszeichen passiert zentral in compileExecuteCommand().
        $command->command = "ADDRPYLE SEQNBR($sequenceNumber) MSGID(CPA32B2) RPY('I')";

        return $this->compileExecuteCommand($blueprint, $command);
    }

    /**
     * Compile a removeReplyListEntry command.
     */
    public function compileRemoveReplyListEntry(Blueprint $blueprint, Fluent $command): string
    {
        $sequenceNumber = $blueprint->getReplyListSequenceNumber();
        $command->command = "RMVRPYLE SEQNBR($sequenceNumber)";

        return $this->compileExecuteCommand($blueprint, $command);
    }

    /**
     * Compile a changeJob command.
     */
    public function compileChangeJob(Blueprint $blueprint, Fluent $command): string
    {
        $command->command = 'CHGJOB INQMSGRPY(*SYSRPYL)';

        return $this->compileExecuteCommand($blueprint, $command);
    }
}
