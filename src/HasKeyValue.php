<?php

namespace atomita\database\eloquent\relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HasKeyValue extends HasOneOrMany
{
    protected $storeClass;

    /**
     * Create a new has one or many relationship instance.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  string  $foreignKey
     * @param  string  $localKey
     * @param  string  $storeClass
     */
    public function __construct(Builder $query, Model $parent, $foreignKey, $localKey, $storeClass = KeyValueStore::class)
    {
        parent::__construct($query, $parent, $foreignKey, $localKey);

        $this->storeClass = $storeClass;
    }

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    public function getResults()
    {
        return $this->newCollection([]);
    }

    /**
     * Create a new KeyValueStore instance.
     *
     * @param  array  $models
     * @return KeyValueStore
     */
    public function newCollection($models = [])
    {
        $storeClass = $this->storeClass;
        return new $storeClass($models, $this);
    }

    /**
     * Initialize the relation on a set of models.
     *
     * @param  array   $models
     * @param  string  $relation
     * @return array
     */
    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }
        return $models;
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  array   $models
     * @param  \Illuminate\Database\Eloquent\Collection  $results
     * @param  string  $relation
     * @return array
     */
    public function match(array $models, Collection $results, $relation)
    {
        return $this->matchOneOrMany($models, $results, $relation, 'key value');
    }

    /**
     * Get the value of a relationship by one or many type.
     *
     * @param  array   $dictionary
     * @param  string  $key
     * @param  string  $type
     * @return mixed
     */
    protected function getRelationValue(array $dictionary, $key, $type)
    {
        return $this->newCollection($dictionary[$key]);
    }

}
