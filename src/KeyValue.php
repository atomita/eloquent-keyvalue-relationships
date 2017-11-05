<?php

namespace atomita\database\eloquent\relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class KeyValue extends Model
{
    /**
     * The parent model of the relationship.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $parent;

    /**
     * The name of the foreign key column.
     *
     * @var string
     */
    protected $foreignKey;

    /**
     * The name of the key column.
     *
     * @var string
     */
    protected $keyColumn;

    /**
     * The name of the value column.
     *
     * @var string
     */
    protected $valueColumn;

    /**
     * The relation.
     *
     * @var HasKeyValue
     */
    protected $relation;

    /**
     * The key value store.
     *
     * @var KeyValueStore
     */
    protected $store;

    /**
     * Create a new KeyValueIndividual model instance.
     *
     * @param  array   $attributes
     * @param  bool    $exists
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  string  $table
     * @param  string  $foreignKey
     * @param  string  $keyColumn
     * @param  string  $valueColumn
     */
    public function __construct($attributes = [],
                                $exists = false,
                                Model $parent = null,
                                $table = null,
                                $foreignKey = null,
                                $keyColumn = null,
                                $valueColumn = null,
                                HasKeyValue $relation = null,
                                KeyValueStore $store = null)
    {
        parent::__construct();

        // The pivot model is a "dynamic" model since we will set the tables dynamically
        // for the instance. This allows it work for any intermediate tables for the
        // many to many relationship that are defined by this developer's classes.
        $this->setTable($table);

        if (! is_null($parent)) {
            $this->setConnection($parent->getConnectionName());
        }

        $this->forceFill($attributes);

        $this->syncOriginal();

        // We store off the parent instance so we will access the timestamp column names
        // for the model, since the pivot model timestamps aren't easily configurable
        // from the developer's point of view. We can use the parents to get these.
        $this->parent = $parent;

        $this->relation = $relation;

        $this->store = $store;

        $this->foreignKey = $foreignKey;

        $this->keyColumn = $keyColumn;

        $this->valueColumn = $valueColumn;

        $this->exists = $exists;

        $this->timestamps = $this->hasTimestampAttributes();
    }

    public function getRelation($relation = null)
    {
        return $this->relation ?: (empty($this->store) ? null : $this->store->getRelation());
    }

    public function setRelation($relation, $value = null)
    {
        $this->relation = $relation;
    }

    public function getStore()
    {
        return $this->store ?: null;
    }

    public function setStore(KeyValueStore $store)
    {
        $this->store = $store;
    }

    /**
     * Set the keys for a save update query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function setKeysForSaveQuery(Builder $query)
    {
        $query->where($this->getForeignKey(), $this->getAttribute($this->getForeignKey()));

        return $query->where($this->getKeyColumn(), $this->getAttribute($this->getKeyColumn()));
    }

    public function remove()
    {
        $result = $this->exists ? $this->delete() : true;
        if ($result) {
            $key = $this->getAttribute($this->getKeyColumn());
            unset($this->store->$key);
        }
        return $result;
    }

    /**
     * Get a parent model.
     *
     * @return  \Illuminate\Database\Eloquent\Model
     */
    public function getParent()
    {
        if (! empty($this->parent)) {
            return $this->parent;
        } elseif ($relation = $this->getRelation()) {
            return $relation->getParent();
        } elseif ($store = $this->getStore()) {
            return $store->getRelation()->getParent();
        }
        return null;
    }

    /**
     * Get the default foreign key name for the model.
     *
     * @return string
     */
    public function getForeignKey()
    {
        return $this->foreignKey ?: parent::getForeignKey();
    }

    /**
     * Get the name of the "key" column.
     *
     * @return string
     */
    public function getKeyColumn()
    {
        return $this->keyColumn;
    }

    /**
     * Get the name of the "value" column.
     *
     * @return string
     */
    public function getValueColumn()
    {
        return $this->valueColumn;
    }

    /**
     * Delete the pivot model record from the database.
     *
     * @return int
     */
    public function delete()
    {
        return $this->getDeleteQuery()->delete();
    }

    /**
     * Get the query builder for a delete operation on the pivot.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function getDeleteQuery()
    {
        $foreign = $this->getAttribute($this->foreignKey);

        $query = $this->newQuery()->where($this->foreignKey, $foreign);

        return $query->where($this->getKeyColumn(), $this->getAttribute($this->getKeyColumn()));
    }

    /**
     * Determine if the pivot model has timestamp attributes.
     *
     * @return bool
     */
    public function hasTimestampAttributes()
    {
        return array_key_exists($this->getCreatedAtColumn(), $this->attributes);
    }

    /**
     * Get the name of the "created at" column.
     *
     * @return string
     */
    public function getCreatedAtColumn()
    {
        if (is_null($this->parent)) {
            return '';
        }
        return $this->parent->getCreatedAtColumn();
    }

    /**
     * Get the name of the "updated at" column.
     *
     * @return string
     */
    public function getUpdatedAtColumn()
    {
        if (is_null($this->parent)) {
            return '';
        }
        return $this->parent->getUpdatedAtColumn();
    }

    /**
     * Create a new instance of the given key-value.
     *
     * @param  array   $attributes
     * @param  bool    $exists
     * @return static
     */
    public function newInstance($attributes = [], $exists = false)
    {
        $parent = $this->getParent();

        $foreignKey = $this->getForeignKey();

        $instance = new static((array) $attributes,
                               $exists,
                               $parent,
                               $this->getTable(),
                               $this->getForeignKey(),
                               $this->getKeyColumn(),
                               $this->getValueColumn(),
                               $this->getRelation(),
                               $this->getStore());

        if ($parent) {
            $instance->setConnection($parent->getConnectionName());
        }

        return $instance;
    }

    /**
     * Create a new instance of the given key-value.
     *
     * @param  array   $attributes
     * @param  bool    $exists
     * @param  KeyValueStore  $store
     * @return static
     */
    public function newFromStore($attributes = [], $exists = false, $store)
    {
        $relation = $store->getRelation();

        $releted = $relation->getRelated();

        $parent = $relation->getParent();

        $foreignKey = $releted->getForeignKey();

        $instance = new static([],
                          $exists,
                          $parent,
                          $releted->getTable(),
                          $foreignKey,
                          $releted->getKeyColumn(),
                          $releted->getValueColumn(),
                          $relation,
                          $store);

        $instance->setRawAttributes((array) $attributes, true);

        if (! isset($instance->$foreignKey)) {
            $instance->$foreignKey = $relation ? $relation->getParentKey() : $this->getAttribute($foreignKey);
        }

        $instance->setConnection($parent->getConnectionName());

        return $instance;
    }

    /**
     * Create a new Eloquent Collection instance.
     *
     * @param  array  $models
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function newCollection(array $models = [])
    {
        if (! empty($this->store)) {
            return $this->store->newInstance($models);
        } elseif (! empty($this->relation)) {
            return $this->relation->newCollection($models);
        } else {
            return parent::newCollection($models);
        }
    }

    /**
     * Touch the parent of the model.
     */
    public function touchParent()
    {
        if ($parent = $this->getParent()) {
            return $parent->touch();
        }
    }
}
