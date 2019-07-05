<?php

declare(strict_types=1);

namespace Somnambulist\ReadModels;

use Doctrine\Common\Inflector\Inflector;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;
use DomainException;
use IlluminateAgnostic\Str\Support\Arr;
use IlluminateAgnostic\Str\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Pagerfanta\Pagerfanta;
use Somnambulist\Collection\Collection;
use Somnambulist\ReadModels\Contracts\Queryable;
use Somnambulist\ReadModels\Relationships\AbstractRelationship;
use Somnambulist\ReadModels\Relationships\BelongsToMany;
use Somnambulist\ReadModels\Relationships\HasOne;
use Somnambulist\ReadModels\Relationships\HasOneToMany;
use Somnambulist\ReadModels\Utils\ClassHelpers;
use Somnambulist\ReadModels\Utils\ProxyTo;
use Somnambulist\ReadModels\Utils\StrConverter;

/**
 * Class Model
 *
 * @package    Somnambulist\ReadModels
 * @subpackage Somnambulist\ReadModels\Model
 *
 * @method static Model find(int|string $id)
 * @method static Model findOrFail(int|string $id)
 * @method ExpressionBuilder expression()
 * @method Collection fetch()
 * @method int count()
 * @method Pagerfanta paginate(int $page = 1, int $perPage = 30)
 * @method QueryBuilder getQueryBuilder()
 * @method Builder andHaving(string $expression)
 * @method Builder getParameter(string|int $key)
 * @method Builder getParameters()
 * @method Builder getParameterType(string $key)
 * @method Builder getParameterTypes()
 * @method Builder groupBy(string $column)
 * @method Builder having(string $expression)
 * @method Builder innerJoin(string $fromAlias, string $join, string $alias, $conditions)
 * @method Builder join(string $fromAlias, string $join, string $alias, $conditions)
 * @method Builder leftJoin(string $fromAlias, string $join, string $alias, $conditions)
 * @method Builder limit(int $limit)
 * @method Builder offset(int $offset)
 * @method Builder orderBy(string $column, string $dir)
 * @method Builder orHaving(string $expression)
 * @method Builder orWhere(string $expression, array $values)
 * @method Builder orWhereBetween(string $column, mixed $start, mixed $end)
 * @method Builder orWhereColumn(string $column, string $operator, mixed $value)
 * @method Builder orWhereIn(string $column, array $values)
 * @method Builder orWhereNotBetween(string $column, mixed $start, mixed $end)
 * @method Builder orWhereNotIn(string $column, array $values)
 * @method Builder orWhereNotNull(string $column)
 * @method Builder orWhereNull(string $column)
 * @method Builder rightJoin(string $fromAlias, string $join, string $alias, $conditions)
 * @method Builder select(string ...$columns)
 * @method Builder setParameter(string|int $key, mixed $value, $type = null)
 * @method Builder setParameters(array $parameters)
 * @method Builder where(string $expression, array $values)
 * @method Builder whereBetween(string $column, mixed $start, mixed $end)
 * @method Builder whereColumn(string $column, string $operator, mixed $value)
 * @method Builder whereIn(string $column, array $values)
 * @method Builder whereNotBetween(string $column, mixed $start, mixed $end)
 * @method Builder whereNotIn(string $column, array $values)
 * @method Builder whereNotNull(string $column)
 * @method Builder whereNull(string $column)
 * @method Builder wherePrimaryKey(int|string $id)
 */
abstract class Model implements JsonSerializable, Queryable
{

    /**
     * A prefix used to flag attributes generated by ReadModels
     */
    public const INTERNAL_KEY_PREFIX           = '__somnambulist';

    /**
     * The identity of the source of a relationship (left side)
     */
    public const RELATIONSHIP_SOURCE_MODEL_REF = self::INTERNAL_KEY_PREFIX . '_source_model_ref';

    /**
     * The identity of the target of a relationship (right side)
     */
    public const RELATIONSHIP_TARGET_MODEL_REF = self::INTERNAL_KEY_PREFIX . '_target_model_ref';



    /**
     * The pool of connections, one per model class + a default
     *
     * @var array|Connection[]
     */
    protected static $connections = [];

    /**
     * The table associated with the model, will be guessed if not set
     *
     * @var string
     */
    protected $table;

    /**
     * A default table alias to automatically scope table/columns
     *
     * @var string|null
     */
    protected $tableAlias;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The relationships to eager load on every query
     *
     * @var array
     */
    protected $with = [];

    /**
     * The loaded database properties
     *
     * @var array
     */
    protected $attributes = [];

    /**
     * Convert to a PHP type based on the registered Doctrine Types
     *
     * @var array
     */
    protected $casts = [];

    /**
     * Convert sets of attributes into embedded value-objects
     *
     * The object will be assigned to the attribute key given. Parameters must
     * be set in the constructor order.
     *
     * @var array
     */
    protected $embeds = [];

    /**
     * Set what can be exported, or not by attribute and relationship name
     *
     * By default ALL attributes are exported; to export specific attributes, set
     * them in the attributes array.
     *
     * Contrary: by default NO relationships are exported. You must explicitly set
     * which ones to export.
     *
     * These can be overridden before calling toArray/toJson on the exporter but will
     * be used when calling jsonSerialize().
     *
     * @var array
     */
    protected $exports = [
        'attributes'    => [],
        'relationships' => [],
    ];

    /**
     * The various loaded relationships this model has
     *
     * @var array
     */
    private $relationships = [];

    /**
     * @var ModelExporter
     */
    private $exporter;

    /**
     * Constructor.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->mapAttributes($attributes);
    }

    /**
     * @param string $method
     * @param array  $parameters
     *
     * @return Model
     */
    public function __call($method, $parameters)
    {
        if (isset($this->attributes[$attr = Str::snake($method)])) {
            return $this->getAttribute($attr);
        }

        return (new ProxyTo())($this->newQuery(), $method, $parameters);
    }

    /**
     * Handle dynamic static method calls into the method.
     *
     * @param string $method
     * @param array  $parameters
     *
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        return (new static)->{$method}(...$parameters);
    }

    /**
     * Allows accessing the attributes and relationships as properties
     *
     * @param string $name
     *
     * @return mixed|AbstractRelationship|null
     */
    public function __get($name)
    {
        return $this->getAttribute($name);
    }

    public function __set($name, $value)
    {
        throw new DomainException(sprintf('Models are read-only and cannot be changed once loaded'));
    }

    public function __unset($name)
    {
        throw new DomainException(sprintf('Models are read-only and cannot be changed once loaded'));
    }

    public function __isset($name)
    {
        return !is_null($this->getAttribute($name));
    }

    public function __toString()
    {
        return $this->export()->toJson();
    }

    /**
     * Set the DBAL Connection to use by default or for a specific model
     *
     * The model class name should be used and then that connection will be used with all
     * instances of that model. A default connection should still be provided as a fallback.
     *
     * @param Connection $connection
     * @param string     $model
     */
    public static function bindConnection(Connection $connection, string $model = 'default'): void
    {
        static::$connections[$model] = $connection;
    }

    /**
     * Get a specified or the default connection
     *
     * @param string $model
     *
     * @return Connection
     * @throws InvalidArgumentException if connection has not been setup
     */
    public static function connection(string $model = null): Connection
    {
        $try = $model ?? 'default';

        if ('default' !== $model && !isset(static::$connections[$try])) {
            $try = 'default';
        }

        if (null === $connection = (static::$connections[$try] ?? null)) {
            throw new InvalidArgumentException(
                sprintf('A connection for "%s" or "%s" has not been defined', $model, $try)
            );
        }

        return $connection;
    }

    /**
     * Eager load the specified relationships on this model
     *
     * Allows dot notation to load related.related objects.
     *
     * @param string ...$relations
     *
     * @return Builder
     */
    public static function with(...$relations): Builder
    {
        return static::query()->with(...$relations);
    }

    /**
     * Starts a new query builder process without any constraints
     *
     * @return Builder
     */
    public static function query(): Builder
    {
        return (new static)->newQuery();
    }

    /**
     * Hydrates the model and maps the results to the object
     *
     * If any casts have been defined and if a type exists for that cast in the DBAL
     * types, it will be run against the value from the database. If the type requires
     * a resource, the cast type should be prefixed with: "resource:"
     *
     * If any embeds have been defined and if the attributes match, additional objects
     * will be hydrated into the attributes for each mapped set. This allows embedded
     * value-objects to be re-used in the read-models.
     *
     * @param array $attributes
     */
    private function mapAttributes(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $key = $this->removeTableAliasFrom($key);

            if (null !== $type = $this->getCastType($key)) {
                if (Str::startsWith($type, 'resource:')) {
                    $value = is_null($value) ? null : StrConverter::toResource($value);
                    $type  = Str::replaceFirst('resource:', '', $type);
                }

                $value = Type::getType($type)->convertToPHPValue(
                    $value, static::connection(static::class)->getDatabasePlatform()
                );
            }

            $this->attributes[$key] = $value;
        }

        if (count($this->attributes) > 0) {
            foreach ($this->embeds as $key => [$class, $args]) {
                $this->attributes[$key] = $this->makeEmbeddableObject($class, $args);
            }
        }
    }

    private function makeEmbeddableObject($class, $args): ?object
    {
        $params = [];

        foreach ($args as $arg) {
            if (is_array($arg)) {
                $params[] = $this->makeEmbeddableObject($arg[0], $arg[1]);
            } elseif (null !== $value = ($this->attributes[$arg] ?? null)) {
                $params[] = $value;
            }
        }

        if (empty($params) || count($params) !== count($args)) {
            return null;
        }

        if (Str::contains($class, '::')) {
            return call_user_func_array($class, $params);
        }

        return new $class(...$params);
    }

    /**
     * @param string $key
     *
     * @return string|null
     */
    private function getCastType(string $key): ?string
    {
        $cast = $this->casts[$key] ?? null;

        return $cast ? trim(strtolower($cast)) : $cast;
    }

    public function newQuery(): Builder
    {
        return
            (new Builder($this, static::connection(static::class)->createQueryBuilder()))
                ->with($this->with)
        ;
    }

    /**
     * Creates a new instance from the (optional) attributes
     *
     * @param array $attributes
     *
     * @return Model
     */
    public function newModel(array $attributes = []): Model
    {
        return new static($attributes);
    }

    /**
     * Returns all attributes, excluding the internal attributes
     *
     * @return array
     */
    public function getAttributes(): array
    {
        return (new FilterGeneratedKeysFromCollection())($this->attributes);
    }

    /**
     * Get the requests attribute or relationship
     *
     * If a mutator is defined (getXxxxAttribute method), the attribute will be passed through that first.
     * If the relationship has not been loaded, it will be at this point.
     *
     * @param string $name
     *
     * @return mixed|null
     */
    public function getAttribute(string $name)
    {
        if (isset($this->attributes[$name])) {
            $mutator = sprintf('get%sAttribute', Str::studly($name));

            if (method_exists($this, $mutator)) {
                return $this->{$mutator}($this->attributes[$name]);
            }

            return $this->attributes[$name];
        }

        if (method_exists(self::class, $name)) {
            return null;
        }

        return $this->getRelationshipValue($name);
    }

    public function prefixColumnWithTableAlias(string $column): string
    {
        if (false !== stripos($column, '.')) {
            return $column;
        }

        return sprintf('%s.%s', ($this->getTableAlias() ?: $this->getTable()), $column);
    }

    public function removeTableAliasFrom(string $key): string
    {
        return stripos($key, '.') !== false ? Arr::last(explode('.', $key)) : $key;
    }

    public function export(): ModelExporter
    {
        if (!$this->exporter instanceof ModelExporter) {
            $this->exporter = new ModelExporter(
                $this, $this->exports['attributes'] ?? [], $this->exports['relationships'] ?? []
            );
        }

        return $this->exporter;
    }

    public function getTable(): string
    {
        return $this->table ?? Inflector::tableize(Inflector::pluralize(ClassHelpers::getObjectShortClassName($this)));
    }

    public function getTableAlias(): ?string
    {
        return $this->tableAlias;
    }

    public function getPrimaryKey()
    {
        return $this->attributes[$this->getPrimaryKeyName()];
    }

    public function getPrimaryKeyName(): string
    {
        return $this->primaryKey;
    }

    public function getPrimaryKeyWithTableAlias(): string
    {
        return $this->prefixColumnWithTableAlias($this->getPrimaryKeyName());
    }

    /**
     * Creates a foreign key name from the current class name and primary key name
     *
     * This is used in relationships if a specific foreign key column name is not
     * defined on the relationship.
     *
     * @return string
     */
    public function getForeignKey(): string
    {
        return Str::slug(ClassHelpers::getObjectShortClassName($this), '_') . '_' . $this->getPrimaryKeyName();
    }

    public function jsonSerialize(): array
    {
        return $this->export()->toArray();
    }

    /**
     * Returns the relationship definition defined by the method name
     *
     * E.g. a User model hasMany Roles, the method would be "roles()".
     *
     * @param string $method
     *
     * @return AbstractRelationship
     */
    public function getRelationship(string $method): AbstractRelationship
    {
        $relationship = $this->$method();

        if (!$relationship instanceof AbstractRelationship) {
            if (is_null($relationship)) {
                throw new LogicException(sprintf(
                    '%s::%s must return a relationship instance, but "null" was returned. Was the "return" keyword used?', static::class, $method
                ));
            }

            throw new LogicException(sprintf(
                '%s::%s must return a relationship instance.', static::class, $method
            ));
        }

        return $relationship;
    }

    /**
     * @param string $key
     *
     * @return null|Collection|Model[]|Model
     */
    private function getRelationshipValue(string $key)
    {
        if ($this->isRelationshipLoaded($key)) {
            return $this->relationships[$key];
        }

        if (method_exists($this, $key)) {
            return $this->getRelationshipFromMethod($key);
        }

        return null;
    }

    /**
     * Get the objects on a relationship, loading them if not already loaded
     *
     * @param string $method
     *
     * @return Collection|Model[]|Model
     *
     * @throws LogicException
     */
    private function getRelationshipFromMethod($method)
    {
        $relation = $this->$method();

        if (!$relation instanceof AbstractRelationship) {
            if (is_null($relation)) {
                throw new LogicException(sprintf(
                    '%s::%s must return a relationship instance, but "null" was returned. Was the "return" keyword used?', static::class, $method
                ));
            }

            throw new LogicException(sprintf(
                '%s::%s must return a relationship instance.', static::class, $method
            ));
        }

        $results = $relation->hasMany() ? $relation->fetch() : $relation->fetch()->first();

        $this->setRelationshipResults($method, $results);

        return $results;
    }

    /**
     * Determine if the given relation is loaded.
     *
     * @param string $key
     *
     * @return bool
     */
    private function isRelationshipLoaded($key): bool
    {
        return array_key_exists($key, $this->relationships);
    }

    /**
     * Set the given relationship on the model.
     *
     * @param string $relation
     * @param mixed  $value
     */
    private function setRelationshipResults($relation, $value): void
    {
        $this->relationships[$relation] = $value;
    }

    /**
     * Define a new many to many relationship
     *
     * The table is the joining table between the source and the target. The source is
     * the object at the left hand side of the relationship, the target is on the right.
     * For example: User -> Roles through a user_roles table, with user_id, role_id as
     * columns. The relationship would be defined as a User has Roles so the source is
     * user_id and the target is role_id.
     *
     * If the table is not given, it will be guessed using the source table singularized
     * and the target pluralized, separated by an underscore e.g.: users and roles
     * would create: user_roles
     *
     * @param string      $class
     * @param string|null $table
     * @param string|null $tableSourceKey
     * @param string|null $tableTargetKey
     * @param string|null $sourceKey      The source models primary key name
     * @param string|null $targetKey      The target models primary key name
     *
     * @return BelongsToMany
     */
    protected function belongsToMany(string $class, ?string $table = null,
        ?string $tableSourceKey = null, ?string $tableTargetKey = null,
        ?string $sourceKey = null, ?string $targetKey = null
    ): BelongsToMany
    {
        /** @var Model $instance */
        $instance       = new $class();
        $table          = $table ?: sprintf('%s_%s', Inflector::singularize($this->getTable()), $instance->getTable());
        $tableSourceKey = $tableSourceKey ?: $this->getForeignKey();
        $tableTargetKey = $tableTargetKey ?: $instance->getForeignKey();
        $sourceKey      = $sourceKey ?: $this->getPrimaryKeyName();
        $targetKey      = $targetKey ?: $instance->getPrimaryKeyName();

        return new BelongsToMany($instance->newQuery(), $this, $table, $tableSourceKey, $tableTargetKey, $sourceKey, $targetKey);
    }

    /**
     * Define a one to many relationship
     *
     * Here, the parent has many children, so a User can have many addresses.
     * The foreign key is the name of the parents key in the childs table.
     * local key is the childs primary key.
     *
     * indexBy allows a column on the child to be used as the key in the returned
     * collection. Note: if this is specified, then there can be only a single
     * instance of that key returned. This would usually be used on related objects
     * with a type where, the parent can only have one of each type e.g.: a contact
     * has a "type" field for: home, office, cell etc.
     *
     * @param string      $class
     * @param string      $foreignKey
     * @param string      $localKey
     * @param string|null $indexBy
     *
     * @return HasOneToMany
     */
    protected function hasMany(string $class, string $foreignKey, string $localKey, ?string $indexBy = null): HasOneToMany
    {
        /** @var Model $instance */
        $instance   = new $class();
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey   = $localKey ?: $this->getPrimaryKeyName();

        return new HasOneToMany(
            $instance->newQuery(), $this, $instance->getTable() . '.' . $foreignKey, $localKey, $indexBy
        );
    }

    /**
     * Defines a one to one relationship
     *
     * Here the parent has only one child and the child only has that parent. If
     * multiple records end up being stored, then only the first will be loaded.
     *
     * @param string $class
     * @param string $foreignKey
     * @param string $localKey
     *
     * @return HasOne
     */
    protected function hasOne(string $class, string $foreignKey, string $localKey): HasOne
    {
        /** @var Model $instance */
        $instance   = new $class();
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey   = $localKey ?: $this->getPrimaryKeyName();

        return new HasOne(
            $instance->newQuery(), $this, $instance->getTable() . '.' . $foreignKey, $localKey
        );
    }
}
