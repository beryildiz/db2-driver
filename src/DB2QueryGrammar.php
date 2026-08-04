<?php

declare(strict_types=1);

namespace Beryildiz\DB2Driver;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;

class DB2QueryGrammar extends Grammar
{
    /**
     * The format for database stored dates.
     */
    protected ?string $dateFormat = null;

    /**
     * Offset compatibility mode true triggers FETCH FIRST X ROWS and ROW_NUM behavior for older versions of DB2
     *
     * @var bool
     */
    protected $offsetCompatibilityMode = true;

    /** @param string $value */
    protected function wrapValue($value): string
    {
        if ($value === '*') {
            return $value;
        }

        return str_replace('"', '""', $value);
    }

    protected function compileLimit(Builder $query, $limit): string
    {
        if ($this->offsetCompatibilityMode) {
            return "FETCH FIRST $limit ROWS ONLY";
        }

        return parent::compileLimit($query, $limit);
    }

    public function compileSelect(Builder $query): string
    {
        if (! $this->offsetCompatibilityMode) {
            return parent::compileSelect($query);
        }

        if (is_null($query->columns)) {
            $query->columns = ['*'];
        }

        $components = $this->compileComponents($query);

        // If an offset is present on the query, we will need to wrap the query in
        // a big "ANSI" offset syntax block. This is very nasty compared to the
        // other database systems but is necessary for implementing features.
        if ($query->offset > 0) {
            return $this->compileAnsiOffset($query, $components);
        }

        return $this->concatenate($components);
    }

    protected function compileAnsiOffset(Builder $query, array $components): string
    {
        // An ORDER BY clause is required to make this offset query work, so if one does
        // not exist we'll just create a dummy clause to trick the database and so it
        // does not complain about the queries for not having an "order by" clause.
        if (! isset($components['orders'])) {
            $components['orders'] = 'order by 1';
        }

        unset($components['limit']);

        // We need to add the row number to the query so we can compare it to the offset
        // and limit values given for the statements. So we will add an expression to
        // the "select" that will give back the row numbers on each of the records.
        $orderings = $components['orders'];

        $columns = (! empty($components['columns']) ? $components['columns'].', ' : 'select');

        if ($columns == 'select *, ' && $query->from) {
            // Seit Laravel 12 gibt es keine "$this->tablePrefix"-Property mehr auf Grammar;
            // der Prefix wird über die Connection gehalten.
            $columns = 'select '.$this->getTablePrefix().$query->from.'.*, ';
        }

        $components['columns'] = $this->compileOver($orderings, $columns);

        // Order-Bindings in "select" verschieben, damit sie mit dem OVER-Statement korrekt
        // positioniert sind — aber nur, wenn tatsächlich Order-Bindings existieren.
        // isset() ist hier ungeeignet: der 'order'-Key im Bindings-Shape existiert garantiert
        // (siehe Builder::getRawBindings() @return-Shape), also wäre isset() immer true.
        if ($query->getRawBindings()['order'] !== []) {
            $query->addBinding($query->getRawBindings()['order'], 'select');
            $query->setBindings([], 'order');
        }

        unset($components['orders']);

        // Next we need to calculate the constraints that should be placed on the query
        // to get the right offset and limit from our query but if there is no limit
        // set we will just handle the offset only since that is all that matters.
        $constraint = $this->compileRowConstraint($query);

        $sql = $this->concatenate($components);

        // We are now ready to build the final SQL query so we'll create a common table
        // expression from the query and get the records with row numbers within our
        // given limit and offset value that we just put on as a query constraint.
        return $this->compileTableExpression($sql, $constraint);
    }

    protected function compileOver(string $orderings, string $columns): string
    {
        return "{$columns} row_number() over ({$orderings}) as row_num";
    }

    protected function compileRowConstraint(Builder $query): string
    {
        $start = $query->offset + 1;

        if ($query->limit > 0) {
            $finish = $query->offset + $query->limit;

            return "between {$start} and {$finish}";
        }

        return ">= {$start}";
    }

    protected function compileTableExpression(string $sql, string $constraint): string
    {
        return "select * from ({$sql}) as temp_table where row_num {$constraint}";
    }

    protected function compileOffset(Builder $query, $offset): string
    {
        if ($this->offsetCompatibilityMode) {
            return '';
        }

        return parent::compileOffset($query, $offset);
    }

    public function compileExists(Builder $query): string
    {
        $existsQuery = clone $query;

        $existsQuery->columns = [];

        return $this->compileSelect($existsQuery->selectRaw('1 exists')->limit(1));
    }

    /**
     * Get the format for database stored dates.
     *
     * @return string
     */
    public function getDateFormat()
    {
        return $this->dateFormat ?? parent::getDateFormat();
    }

    /**
     * Set the format for database stored dates.
     */
    public function setDateFormat($dateFormat)
    {
        $this->dateFormat = $dateFormat;
    }

    /**
     * Set offset compatibility mode to trigger FETCH FIRST X ROWS and ROW_NUM behavior for older versions of DB2
     */
    public function setOffsetCompatibilityMode($bool)
    {
        $this->offsetCompatibilityMode = $bool;
    }

    /**
     * Compile the SQL statement to define a savepoint.
     *
     * @param  string  $name
     * @return string
     */
    public function compileSavepoint($name)
    {
        return 'SAVEPOINT '.$name.' ON ROLLBACK RETAIN CURSORS';
    }
}
