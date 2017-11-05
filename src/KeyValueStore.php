<?php

namespace atomita\database\eloquent\relations;

use JsonSerializable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Support\Arr;

class KeyValueStore extends Collection
{
    protected $relation;

    /**
     * User exposed observable events.
     *
     * @var array
     */
    protected static $observables = [];

    /**
     * The event dispatcher instance.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    protected static $dispatcher = null;

    /**
     * The array of booted key-value-stores.
     *
     * @var array
     */
    protected static $booted = [];

    /**
     * Create a new key-value-store.
     *
     * @param  mixed  $items
     */
    public function __construct($items = [], HasKeyValue $relation)
    {
        parent::__construct($items);

        $this->relation = $relation;

        $this->items = $this->itemsToAssoc($this->setStoreToModels($items),
                                           $this->relation->getRelated()->getKeyColumn());

        if (empty(static::getEventDispatcher())) {
            if ($event_dispatcher = Eloquent::getEventDispatcher()) {
                static::setEventDispatcher($event_dispatcher);
            }
        }

        $this->bootIfNotBooted();
    }

    /**
     * Check if the model needs to be booted and if so, do it.
     *
     * @return void
     */
    protected function bootIfNotBooted()
    {
        if (! isset(static::$booted[static::class])) {
            static::$booted[static::class] = true;

            $this->fireEvent('booting', false);

            static::boot();

            $this->fireEvent('booted', false);
        }
    }

    /**
     * The "booting" method of the key-value-store.
     *
     * @return void
     */
    protected static function boot()
    {
        static::bootTraits();
    }

    /**
     * Boot all of the bootable traits on the key-value-store.
     *
     * @return void
     */
    protected static function bootTraits()
    {
        $class = static::class;

        foreach (class_uses_recursive($class) as $trait) {
            if (method_exists($class, $method = 'boot'.class_basename($trait))) {
                forward_static_call([$class, $method]);
            }
        }
    }

    /**
     * Get a relation.
     *
     * @return HasKeyValue
     */
    public function getRelation()
    {
        return $this->relation;
    }

    /**
     * Save the models to the database.
     *
     * @param  array  $options
     * @return bool
     */
    public function save(array $options = [])
    {
        // If the "saving" event returns false we'll bail out of the save and return
        // false, indicating that the save failed. This provides a chance for any
        // listeners to cancel save operations if validations fail or whatever.
        if ($this->fireEvent('saving') === false) {
            return false;
        }

        $all_saved = true;
        $saved = false;

        $relation_value = $this->relation->getParentKey();
        $relation_column = $this->relation->getPlainForeignKey();

        foreach ($this->items as $model) {
            $model->setAttribute($relation_column, $relation_value);

            if (! $model->save($options)) {
                $all_saved = false;
            } else {
                $saved = true;
            }
        }

        if ($all_saved || $saved) {
            $this->finishSave($options);
        }

        return $all_saved;
    }

    /**
     * Save the models to the database using transaction.
     *
     * @param  array  $options
     * @return bool
     *
     * @throws \Throwable
     */
    public function saveOrFail(array $options = [])
    {
        return $this->relation->getParent()->getConnection()->transaction(function () use ($options) {
            return $this->save($options);
        });
    }

    /**
     * Finish processing on a successful save operation.
     *
     * @param  array  $options
     * @return void
     */
    protected function finishSave(array $options)
    {
        $this->fireEvent('saved', false);

        if (Arr::get($options, 'touch_parent', false)) {
            $this->touchParent();
        }
    }

    /**
     * Touch the relation's parent of the model.
     *
     * @return void
     */
    public function touchParent()
    {
        return $this->getRelation()->getParent()->touch();
    }

    /**
     * Get the collection of items as a plain array.
     *
     * @return array
     */
    public function toArray()
    {
        $valueColumn = $this->relation->getRelated()->getValueColumn();

        $result = [];

        foreach ($this->items as $key => $item) {
            $value = $item->$valueColumn;

            $result[$key] = $value instanceof Arrayable ? $value->toArray() : $value;
        }

        return $result;
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return array
     */
    public function jsonSerialize()
    {
        $valueColumn = $this->relation->getRelated()->getValueColumn();
        $result = [];
        foreach ($this->items as $key => $item) {
            $value = $item->$valueColumn;
            if ($value instanceof JsonSerializable) {
                $result[$key] = $value->jsonSerialize();
            } elseif ($value instanceof Jsonable) {
                $result[$key] = json_decode($value->toJson(), true);
            } elseif ($value instanceof Arrayable) {
                $result[$key] = $value->toArray();
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * Get the collection of items as JSON.
     *
     * @param  int  $options
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Count the number of items in the collection.
     *
     * @return int
     */
    public function count()
    {
        return count($this->items);
    }

    /**
     * Has in a table.
     *
     * @return boolean
     */
    public function persisted($key)
    {
        if ($this->has($key)) {
            return $this->items[$key]->exists;
        }

        $kv = $this->getKeyValue($key);

        if ($kv->exists) {
            return true;
        }

        $this->remove($key);

        return false;
    }

    /**
     * Remove in the collenction and a table.
     *
     * @return boolean
     */
    public function destroy($key)
    {
        $result = false;

        $kv = $this->getKeyValue($key);

        if ($kv->exists) {
            $result = $kv->delete();
        }

        $this->remove($key);

        return $result;
    }

    /**
     * Has in the collection.
     *
     * @return boolean
     */
    public function has($key)
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * Remove in the collection.
     *
     * @return void
     */
    public function remove($key)
    {
        unset($this->items[$key]);
    }

    /**
     * Dynamically retrieve items on the key-value-store.
     *
     * @param  string  $key
     * @return mixed
     */
    public function getKeyValue($key)
    {
        if (array_key_exists($key, $this->items)) {
            $model = $this->items[$key];
        } else {
            $related = $this->relation->getRelated();

            $query = $this->relation->getQuery()->getModel()->newQuery();

            $model = $query->firstOrNew([
                $related->getForeignKey() => $related->getAttribute($related->getForeignKey()),
                $related->getKeyColumn() => $key,
            ]);

            $model = $related->newFromStore($model->getAttributes(), $model->exists, $this);

            $this->items[$key] = $model;
        }
        return $model;
    }

    /**
     * Get all of the current attributes on the key-value-store.
     *
     * @return array
     */
    public function getAttributes()
    {
        $valueColumn = $this->relation->getRelated()->getValueColumn();

        $attributes = [];

        foreach ($this->items as $key => $value) {
            $attributes[$key] = $value->$valueColumn;
        }

        return $attributes;
    }

    /**
     * Get an attribute from the key-value-store.
     *
     * @param  string  $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        $valueColumn = $this->relation->getRelated()->getValueColumn();

        return $this->getKeyValue($key)->$valueColumn;
    }

    /**
     * Set a given attribute on the key-value-store.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return $this
     */
    public function setAttribute($key, $value)
    {
        if ($value instanceof KeyValue) {
            $this->items[$key] = $value;
        } else {
            $related = $this->relation->getRelated();

            $this->getKeyValue($key); // load

            $valueColumn = $related->getValueColumn();
            $this->items[$key]->$valueColumn = $value;
        }
    }

    /**
     * Fill the key-value-store with an array of attributes. Force mass assignment.
     *
     * @param  array  $attributes
     * @return $this
     */
    public function forceFill(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
    }

    /**
     * Dynamically retrieve items on the key-value-store.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->getKeyValue($method);
    }

    /**
     * Determine if an attribute exists on the key-value-store.
     *
     * @param  string  $key
     * @return bool
     */
    public function __isset($key)
    {
        return $this->getKeyValue($key)->exists;
    }

    /**
     * Unset an attribute on the key-value-store.
     *
     * @param  string  $key
     * @return void
     */
    public function __unset($key)
    {
        unset($this->items[$key]);
    }

    /**
     * Dynamically retrieve items on the key-value-store.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    /**
     * Dynamically set items on the key-value-store.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Determine if an item exists at an offset.
     *
     * @param  mixed  $key
     * @return bool
     */
    public function offsetExists($key)
    {
        return $this->__isset($key);
    }

    /**
     * Get an item at a given offset.
     *
     * @param  mixed  $key
     * @return mixed
     */
    public function offsetGet($key)
    {
        return $this->getAttribute($key);
    }

    /**
     * Set the item at a given offset.
     *
     * @param  mixed  $key
     * @param  mixed  $value
     * @return void
     */
    public function offsetSet($key, $value)
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Unset the item at a given offset.
     *
     * @param  string  $key
     * @return void
     */
    public function offsetUnset($key)
    {
        $this->__unset($key);
    }

    /**
     * Convert the collection to its string representation.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->toJson();
    }

    /**
     * Results array of items from Collection or Arrayable.
     *
     * @param  mixed  $items
     * @return array
     */
    protected function getArrayableItems($items)
    {
        if (is_array($items)) {
            return $items;
        } elseif ($items instanceof self) {
            return $items->all();
        } elseif ($items instanceof Arrayable) {
            return $items->toArray();
        } elseif ($items instanceof Jsonable) {
            return json_decode($items->toJson(), true);
        } elseif ($items instanceof JsonSerializable) {
            return $items->jsonSerialize();
        }

        return (array) $items;
    }

    /**
     * Conversion into an associative array.
     *
     * @param   $items  array
     * @return  array
     */
    protected function itemsToAssoc(array $items, $keyColumn)
    {
        $result = [];
        foreach ($items as $item) {
            $result[$item->$keyColumn] = $item;
        }
        return $result;
    }

    /**
     * Set a store to models.
     *
     * @param   $items  array
     * @return  array
     */
    protected function setStoreToModels(array $items)
    {
        foreach ($items as $item) {
            $item->setStore($this);
        }
        return $items;
    }

    /**
     * Create a new key-value-store.
     *
     * @param   $models
     * @return  static
     */
    public function newInstance($models = [])
    {
        return new static($models, $this->relation);
    }

    /**
     * Get the event dispatcher instance.
     *
     * @return \Illuminate\Contracts\Events\Dispatcher
     */
    public static function getEventDispatcher()
    {
        return static::$dispatcher;
    }

    /**
     * Set the event dispatcher instance.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $dispatcher
     * @return void
     */
    public static function setEventDispatcher(Dispatcher $dispatcher)
    {
        static::$dispatcher = $dispatcher;
    }

    /**
     * Unset the event dispatcher.
     *
     * @return void
     */
    public static function unsetEventDispatcher()
    {
        static::$dispatcher = null;
    }

    /**
     * Fire the given event.
     *
     * @param  string  $event
     * @param  bool  $halt
     * @return mixed
     */
    protected function fireEvent($event, $halt = true)
    {
        if (! isset(static::$dispatcher)) {
            return true;
        }

        $event = "key_value_store.{$event}: ".static::class;

        $method = $halt ? 'until' : 'fire';

        return static::$dispatcher->$method($event, $this);
    }

    /**
     * Remove all of the event listeners for the key-value-store.
     *
     * @return void
     */
    public static function flushEventListeners()
    {
        if (! isset(static::$dispatcher)) {
            return;
        }

        $instance = new static;

        foreach ($instance->getObservableEvents() as $event) {
            static::$dispatcher->forget("key_value_store.{$event}: ".static::class);
        }
    }

    /**
     * Register a event with the dispatcher.
     *
     * @param  string  $event
     * @param  \Closure|string  $callback
     * @param  int  $priority
     * @return void
     */
    protected static function registerEvent($event, $callback, $priority = 0)
    {
        if (isset(static::$dispatcher)) {
            $name = static::class;

            static::$dispatcher->listen("key_value_store.{$event}: {$name}", $callback, $priority);
        }
    }

    /**
     * Register a saving event with the dispatcher.
     *
     * @param  \Closure|string  $callback
     * @param  int  $priority
     * @return void
     */
    public static function saving($callback, $priority = 0)
    {
        static::registerEvent('saving', $callback, $priority);
    }

    /**
     * Register a saved event with the dispatcher.
     *
     * @param  \Closure|string  $callback
     * @param  int  $priority
     * @return void
     */
    public static function saved($callback, $priority = 0)
    {
        static::registerEvent('saved', $callback, $priority);
    }

    /**
     * Register an observer with the Model.
     *
     * @param  object|string  $class
     * @param  int  $priority
     * @return void
     */
    public static function observe($class, $priority = 0)
    {
        $className = is_string($class) ? $class : get_class($class);

        // When registering a model observer, we will spin through the possible events
        // and determine if this observer has that method. If it does, we will hook
        // it into the model's event system, making it convenient to watch these.
        foreach (static::getObservableEvents() as $event) {
            if (method_exists($class, $event)) {
                static::registerEvent($event, $className.'@'.$event, $priority);
            }
        }
    }

    /**
     * Get the observable event names.
     *
     * @return array
     */
    public static function getObservableEvents()
    {
        return array_merge(
            [
                'saving', 'saved',
            ],
            static::$observables
        );
    }

    /**
     * Set the observable event names.
     *
     * @param  array  $observables
     * @return void
     */
    public static function setObservableEvents(array $observables)
    {
        static::$observables = $observables;
    }

    /**
     * Add an observable event name.
     *
     * @param  array|mixed  $observables
     * @return void
     */
    public static function addObservableEvents($observables)
    {
        $observables = is_array($observables) ? $observables : func_get_args();

        static::$observables = array_unique(array_merge(static::$observables, $observables));
    }

    /**
     * Remove an observable event name.
     *
     * @param  array|mixed  $observables
     * @return void
     */
    public static function removeObservableEvents($observables)
    {
        $observables = is_array($observables) ? $observables : func_get_args();

        static::$observables = array_diff(static::$observables, $observables);
    }

    /**
     * Load the value with the specified keys
     *
     * @param  array|string  $keys
     * @return static 
     */
    public function loadValues($keys)
    {
        if (is_string($keys)) {
            $keys = func_get_args();
        }

        $related = $this->relation->getRelated();

        $query = $this->relation->getQuery()->getModel()->newQuery();

        $models = $query->where($related->getForeignKey(), $related->getAttribute($related->getForeignKey()))
            ->whereIn($related->getKeyColumn(), $keys)
            ->get();

        foreach ($models as $model) {
            $model = $related->newFromStore($model->getAttributes(), $model->exists, $this);

            $this->items[$key] = $model;
        }

        return $this;
    }
}
