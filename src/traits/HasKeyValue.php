<?php

namespace atomita\database\eloquent\relations\traits;

use atomita\database\eloquent\relations\HasKeyValue as Relation;
use Illuminate\Support\Arr;

trait HasKeyValue
{
    /**
     * Define a key-value relationship.
     *
     * @param  string  $table
     * @param  string  $foreignKey
     * @param  string  $keyColumn
     * @param  string  $valueColumn
     * @param  string  $related
     * @param  string  $stored
     * @return atomita\database\eloquent\relations\HasKeyValue
     */
    public function hasKeyValue($table = null, $foreignKey = null, $localKey = null,
                                $keyColumn = null, $valueColumn = null,
                                $related = atomita\database\eloquent\relations\KeyValue::class,
                                $stored = atomita\database\eloquent\relations\KeyValueStore::class)
    {
        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        if (is_null($table) || is_null($keyColumn)) {
            $instance = new $related($this, [], $this, __TRAIT__, '', '', '');

            if (is_null($table)) {
                $table = $instance->getTable();
                if ($table === __TRAIT__) {
                    $table = $this->joiningTable($related);
                }
            }

            if (is_null($keyColumn)) {
                $keyColumn = $instance->getKeyColumn();
            }
        }

        $instance = new $related([], false, $this, $table, $foreignKey, $keyColumn, $valueColumn);

        $instance->setAttribute($foreignKey, $this->getAttribute($localKey));

        $query = $instance->newQuery();

        $relation = new Relation($query, $this, $table.'.'.$foreignKey, $localKey, $stored);

        $instance->setRelation($relation);

        return $relation;
    }

    /**
     * Get the relationship name.
     *
     * @return string
     */
    protected function getCallerFunctionName()
    {
        $excludes = func_get_args();
        $self = __FUNCTION__;

        $caller = Arr::first(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), function ($key, $trace) use ($self, $excludes) {
                $caller = $trace['function'];

                return ! in_array($caller, $excludes) && $caller != $self;
            });

        return ! is_null($caller) ? $caller['function'] : null;
    }

}
