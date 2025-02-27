<?php declare(strict_types=1);

namespace Somnambulist\Components\ReadModels;

use function array_intersect_key;
use function array_key_exists;
use function array_map;
use function explode;
use function get_class;
use function Symfony\Component\String\u;

final class ModelIdentityMap
{
    private array $identityMap = [];
    private array $aliases = [];
    private array $relationships = [];

    public function __construct()
    {

    }

    public function hasAlias(string $alias): bool
    {
        return isset($this->aliases[$alias]);
    }

    /**
     * Registers a Models foreign key to its class name for later lookup
     *
     * This is indexed by the foreign key e.g.: User model has a user_id foreign key.
     * In theory this be reasonably unique in a single system. If there are multiple
     * User models in different namespaces it will fail unless they have different
     * foreign key names.
     *
     * A custom foreign key can be registered to the same class e.g.: if no foreign
     * key name is defined on a Doctrine many:many relationship, it will use source
     * and target e.g. a User has many User (parent child) will create a table that
     * has user_source and user_target.
     *
     * @param Model  $model
     * @param string|null $foreignKeyName
     */
    public function registerAlias(Model $model, ?string $foreignKeyName = null): void
    {
        $key = $foreignKeyName ?? $model->meta()->foreignKey();

        if (!$this->hasAlias($key)) {
            $this->aliases[$key] = get_class($model);
        }
    }

    /**
     * Register a connection between the source and target
     *
     * Directionality does not matter in the relationship as it is a lookup table
     * between two models. The end result is an array of: [class_source][id][class_target][id] = [id]
     *
     * @param string $source
     * @param mixed  $sourceId
     * @param string $target
     * @param mixed  $targetId
     */
    public function registerRelationship(string $source, mixed $sourceId, string $target, mixed $targetId): void
    {
        $this->relationships[$source][(string)$sourceId][$target][(string)$targetId] = (string)$targetId;
    }

    /**
     * Processes the attributes to build a relationship map
     *
     * The Model in this instance is the Model we are fetching data for. For example
     * on a 1:many this is the Many model, so Roles for a User. The Role should have
     * a user_id or an aliased auto-generated key that we can strip and lookup in the
     * aliases table ({@see ModelIdentityMap::registerAlias()} and then use this to build
     * a set of identities that belong to the parent.
     *
     * Note: this does not work for belongsTo as the parent is not loaded, so instead
     * the identity map can be used to fetch the parent once loaded and rebind at that
     * point.
     *
     * @param Model $model
     * @param array $attributes
     */
    public function inferRelationshipFromAttributes(Model $model, array &$attributes): void
    {
        foreach ($attributes as $key => $value) {
            $ref = null;

            if (u($key)->startsWith(Relationships\AbstractRelationship::RELATIONSHIP_SOURCE_MODEL_REF)) {
                $ref = array_values(array_slice(explode('__', $key), -1))[0];
                unset($attributes[$key]);
            } elseif ($model->meta()->foreignKey() === $key || $model->getOwningKey() === $key) {
                $ref = $key;
            }

            if ($ref && $value) {
                $source = $this->aliases[$ref];
                $target = get_class($model);

                $this->registerRelationship($source, $value, $target, $attributes[$model->meta()->primaryKeyName()]);
            }
        }
    }

    /**
     * Returns all the related Models for the provided Model
     *
     * If $related is given, only the identities for that Model type are returned.
     * Note: this returns the identities and not instances.
     *
     * @param Model       $model
     * @param string|null $related
     *
     * @return array
     */
    public function getRelatedIdentitiesFor(Model $model, ?string $related = null): array
    {
        $identity = $model->getExternalPrimaryKey() ?? $model->getPrimaryKey();

        if ($related) {
            return
                $this->relationships[get_class($model)][(string)$identity][$related]
                ??
                $this->relationships[get_class($model)][(string)$model->getPrimaryKey()][$related]
                ??
                []
            ;
        }

        return
            $this->relationships[get_class($model)][(string)$identity]
            ??
            $this->relationships[get_class($model)][(string)$model->getPrimaryKey()]
            ??
            []
        ;
    }

    public function add(Model $model): void
    {
        $class = get_class($model);

        if (!$this->has($class, (string)$id = $model->getPrimaryKey())) {
            $this->identityMap[$class][(string)$id] = $model;

            if (null !== $model->getExternalPrimaryKey()) {
                $this->identityMap[$class][(string)$model->getExternalPrimaryKey()] = $model;
            }
        }
    }

    /**
     * Returns all loaded Models from the identity map matching the ids
     *
     * @param string $class
     * @param array  $ids
     *
     * @return array
     */
    public function all(string $class, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        if (!array_key_exists($class, $this->identityMap)) {
            return [];
        }
        $ids = array_map(fn($id) => (string)$id, $ids);

        return array_values(array_intersect_key($this->identityMap[$class], $ids));
    }

    public function get(string $class, $id): ?object
    {
        if ($this->has($class, $id)) {
            return $this->identityMap[$class][(string)$id];
        }

        return null;
    }

    public function has(string $class, $id): bool
    {
        return isset($this->identityMap[$class][(string)$id]);
    }

    /**
     * Remove all data in this identity map
     *
     * Should be called onTerminate / after request processing on long running processes
     */
    public function clear(): void
    {
        $this->identityMap = $this->relationships = $this->aliases = [];
    }

    public function count(): int
    {
        return (int)array_sum(array_map('count', $this->identityMap));
    }

    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function getMap(): array
    {
        return $this->identityMap;
    }

    public function getRelationships(): array
    {
        return $this->relationships;
    }
}
