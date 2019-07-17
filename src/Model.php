<?php

declare(strict_types=1);

namespace Somnambulist\ReadModels;

use Doctrine\Common\Inflector\Inflector;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use DomainException;
use function get_class_methods;
use IlluminateAgnostic\Str\Support\Arr;
use IlluminateAgnostic\Str\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use function method_exists;
use Pagerfanta\Pagerfanta;
use function preg_match;
use Somnambulist\Collection\Contracts\Arrayable;
use Somnambulist\Collection\Contracts\Jsonable;
use Somnambulist\Collection\MutableCollection as Collection;
use Somnambulist\ReadModels\Contracts\AttributeCaster;
use Somnambulist\ReadModels\Contracts\EmbeddableFactory;
use Somnambulist\ReadModels\Contracts\Queryable;
use Somnambulist\ReadModels\Hydrators\DoctrineTypeCaster;
use Somnambulist\ReadModels\Hydrators\SimpleObjectFactory;
use Somnambulist\ReadModels\Relationships\AbstractRelationship;
use Somnambulist\ReadModels\Relationships\BelongsTo;
use Somnambulist\ReadModels\Relationships\BelongsToMany;
use Somnambulist\ReadModels\Relationships\HasOne;
use Somnambulist\ReadModels\Relationships\HasOneToMany;
use Somnambulist\ReadModels\Utils\ClassHelpers;
use Somnambulist\ReadModels\Utils\ProxyTo;
use function array_key_exists;
use function count;
use function explode;
use function is_null;
use function sprintf;
use function stripos;

/**
 * Class Model
 *
 * @package    Somnambulist\ReadModels
 * @subpackage Somnambulist\ReadModels\Model
 *
 * @method static null|Model find(int|string $id)
 * @method static Model findOrFail(int|string $id)
 * @method ExpressionBuilder expression()
 * @method Collection fetch()
 * @method int count()
 * @method Pagerfanta paginate(int $page = 1, int $perPage = 30)
 * @method QueryBuilder getQueryBuilder()
 * @method ModelBuilder andHaving(string $expression)
 * @method ModelBuilder getParameter(string|int $key)
 * @method ModelBuilder getParameters()
 * @method ModelBuilder getParameterType(string $key)
 * @method ModelBuilder getParameterTypes()
 * @method ModelBuilder groupBy(string $column)
 * @method ModelBuilder having(string $expression)
 * @method ModelBuilder innerJoin(string $fromAlias, string $join, string $alias, $conditions)
 * @method ModelBuilder join(string $fromAlias, string $join, string $alias, $conditions)
 * @method ModelBuilder leftJoin(string $fromAlias, string $join, string $alias, $conditions)
 * @method ModelBuilder limit(int $limit)
 * @method ModelBuilder offset(int $offset)
 * @method ModelBuilder orderBy(string $column, string $dir)
 * @method ModelBuilder orHaving(string $expression)
 * @method ModelBuilder orWhere(string $expression, array $values = [])
 * @method ModelBuilder orWhereBetween(string $column, mixed $start, mixed $end)
 * @method ModelBuilder orWhereColumn(string $column, string $operator, mixed $value)
 * @method ModelBuilder orWhereIn(string $column, array $values)
 * @method ModelBuilder orWhereNotBetween(string $column, mixed $start, mixed $end)
 * @method ModelBuilder orWhereNotIn(string $column, array $values)
 * @method ModelBuilder orWhereNotNull(string $column)
 * @method ModelBuilder orWhereNull(string $column)
 * @method ModelBuilder rightJoin(string $fromAlias, string $join, string $alias, $conditions)
 * @method ModelBuilder select(string ...$columns)
 * @method ModelBuilder setParameter(string|int $key, mixed $value, $type = null)
 * @method ModelBuilder setParameters(array $parameters)
 * @method ModelBuilder where(string $expression, array $values = [])
 * @method ModelBuilder whereBetween(string $column, mixed $start, mixed $end)
 * @method ModelBuilder whereColumn(string $column, string $operator, mixed $value)
 * @method ModelBuilder whereIn(string $column, array $values)
 * @method ModelBuilder whereNotBetween(string $column, mixed $start, mixed $end)
 * @method ModelBuilder whereNotIn(string $column, array $values)
 * @method ModelBuilder whereNotNull(string $column)
 * @method ModelBuilder whereNull(string $column)
 * @method ModelBuilder wherePrimaryKey(int|string $id)
 */
abstract class Model implements Arrayable, Jsonable, JsonSerializable, Queryable
{

    /**
     * A prefix used to flag attributes generated by ReadModels
     *
     * Note: the maximum alias length can be severely constrained by the database.
     * For example: MySQL has a 256 character limit; except on views where it is
     * reduced to 64 characters. Other databases can be 63 (Postgres) 128 (Oracle),
     * 255 or 1024. The result is that any internal prefixes have to be short,
     * raising the chance of collisions or query errors.
     */
    public const INTERNAL_KEY_PREFIX           = '__srm';

    /**
     * The identity of the source of a relationship (left side)
     */
    public const RELATIONSHIP_SOURCE_MODEL_REF = self::INTERNAL_KEY_PREFIX . '_src_ref';

    /**
     * The identity of the target of a relationship (right side)
     */
    public const RELATIONSHIP_TARGET_MODEL_REF = self::INTERNAL_KEY_PREFIX . '_tar_ref';



    /**
     * The pool of connections, one per model class + a default
     *
     * @var array|Connection[]
     * @internal
     */
    private static $connections = [];

    /**
     * @var ModelIdentityMap
     * @internal
     */
    private static $identityMap;

    /**
     * @var AttributeCaster
     */
    protected static $attributeCaster;

    /**
     * @var EmbeddableFactory
     */
    protected static $embeddableFactory;

    /**
     * The table associated with the model, will be guessed if not set
     *
     * Override to set a specific table if it does not match the class name.
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
     * The primary key for the model
     *
     * This is the primary identifier used by the database; it is not necessarily what
     * you would expose. For example: when using a relational database, it is more
     * efficient to use auto-increment / sequence integers than UUIDs as keys; but you
     * want the UUID as the external value. To support the database mappings though
     * this needs to be set to the _internal_ identifier. This way related models can
     * be set using the integer keys.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The primary key used for external references
     *
     * Where the {@see Model::$primaryKey} is the internal database key, this is the
     * key that is used outside of the scope of the database i.e. the actual entity
     * key. Typically this will be a UUID or GUID - a globally unique identifier that
     * can be used across databases, APIs etc.
     *
     * By default this is not set, however if a column name is used, then the model
     * will be registered in the identity map with this key, along with the primary
     * key, allowing reverse lookups by UUID as well as the table id key.
     *
     * This allows in-direct connections between aggregate roots that otherwise could
     * not be linked. For example: using the Users UUID as the foreign key in a
     * separate Blog table instead of the internal integer ID.
     *
     * Typically this would be used on a BelongsTo relationship. See the example in the
     * tests of a User having a profile that is linked by UUID.
     *
     * @var string|null
     */
    protected $externalPrimaryKey = null;

    /**
     * How the primary key appears as a foreign key
     *
     * If not set, the short classname will be used, converted to snake_case and the
     * primary key appended. For example: User -> user_id. As this library is intended
     * to be used as a read-only view-model, the model class might be: UserReadModel or
     * UserView that would lead to: user_read_model_id or user_view_id. This property
     * can be set to override the builder logic to use: user_id or another variant.
     *
     * Note: this applies to 1:m and 1:1 and 1:m reversed relationships. m:m uses the
     * keys defined on the relationship to build the relationship keys.
     *
     * @var string|null
     */
    protected $foreignKey = null;

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
     * <code>
     * [
     *     'uuid' => 'uuid',
     *     'location' => 'resource:geometry',
     *     'created_at' => 'datetime',
     *     'updated_at' => 'datetime',
     * ]
     * </code>
     *
     * @var array
     */
    protected $casts = [];

    /**
     * Convert sets of attributes into embedded value-objects
     *
     * The object will be assigned to the attribute key given. Parameters must
     * be set in the constructor order. The attributes used to make the embed
     * can be removed by providing `true` after the argument array:
     *
     * <code>
     * [
     *     'address' => [
     *         App\Models\Address::class, ['address_line_1', 'address_line_2', 'town'], true
     *     ]
     * ]
     * </code>
     *
     * Embeddables can be nested:
     *
     * <code>
     * [
     *     'address' => [
     *         App\Models\Address::class, [
     *             'address_line_1', 'address_line_2', 'town',
     *             ['App\Models\Country::create', ['country',], true
     *         ], true
     *     ]
     * ]
     * </code>
     *
     * If an element is optional, prefix it with a ? - note that this will only work if the
     * value is `null`. `false`, 0 and empty string are not considered to be optional. For
     * example: parts of the address might be optional but an address should still be created.
     * The arguments must be defined in the order of the object constructor or static factory
     * method.
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
     * <code>
     * [
     *     'attributes' => ['uuid' => 'id', 'name', 'slug', 'url'],
     *     'relationships' => ['addresses', 'contacts'],
     * ]
     * </code
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
     * @internal
     */
    private $relationships = [];

    /**
     * @var ModelExporter
     * @internal
     */
    private $exporter;

    /**
     * The field name that flags the owner record; used by identity map
     *
     * @var string
     * @internal
     */
    private $owningKey;

    /**
     * Constructor.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->getIdentityMap()->registerAlias($this);

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
        $mutator   = $this->getAttributeMutator($method);
        $attribute = Str::snake($method);

        if (array_key_exists($attribute, $this->attributes) || method_exists($this, $mutator)) {
            return $this->getAttribute($attribute);
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
        self::$connections[$model] = $connection;
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

        if ('default' !== $model && !array_key_exists($try, self::$connections)) {
            $try = 'default';
        }

        if (null === $connection = (self::$connections[$try] ?? null)) {
            throw new InvalidArgumentException(
                sprintf('A connection for "%s" or "%s" has not been defined', $model, $try)
            );
        }

        return $connection;
    }

    /**
     * Change the primary attribute hydrator to another implementation
     *
     * Affects all models; should not be changed once objects have been loaded.
     *
     * @param AttributeCaster $hydrator
     */
    public static function bindAttributeCaster(AttributeCaster $hydrator): void
    {
        static::$attributeCaster = $hydrator;
    }

    /**
     * Change the embeddable objects hydrator to another implementation
     *
     * Affects all models; should not be changed once objects have been loaded.
     *
     * @param EmbeddableFactory $hydrator
     */
    public static function bindEmbeddableFactory(EmbeddableFactory $hydrator): void
    {
        static::$embeddableFactory = $hydrator;
    }

    private function getAttributeCaster(): AttributeCaster
    {
        if (static::$attributeCaster instanceof AttributeCaster) {
            return static::$attributeCaster;
        }

        return static::$attributeCaster = new DoctrineTypeCaster();
    }

    private function getEmbeddableFactory(): EmbeddableFactory
    {
        if (static::$embeddableFactory instanceof EmbeddableFactory) {
            return static::$embeddableFactory;
        }

        return static::$embeddableFactory = new SimpleObjectFactory();
    }

    public static function getIdentityMap(): ModelIdentityMap
    {
        if (self::$identityMap instanceof ModelIdentityMap) {
            return self::$identityMap;
        }

        return self::$identityMap = new ModelIdentityMap();
    }

    /**
     * Eager load the specified relationships on this model
     *
     * Allows dot notation to load related.related objects.
     *
     * @param string ...$relations
     *
     * @return ModelBuilder
     */
    public static function with(...$relations): ModelBuilder
    {
        return static::query()->with(...$relations);
    }

    /**
     * Starts a new query builder process without any constraints
     *
     * @return ModelBuilder
     */
    public static function query(): ModelBuilder
    {
        return (new static)->newQuery();
    }

    /**
     * Hydrates the model and maps the results to the object
     *
     * Uses the configured hydrators to convert the attributes to types. The hydrators
     * can be swapped for alternative implementations. The default will use Doctrine
     * Types and convert embeddable's to objects.
     *
     * @param array $attributes
     */
    private function mapAttributes(array $attributes): void
    {
        $this->attributes = $this->getAttributeCaster()->cast($this, $attributes, $this->casts);

        if (count($this->attributes) > 0 && count($this->embeds) > 0) {
            $factory = $this->getEmbeddableFactory();

            foreach ($this->embeds as $key => $options) {
                $this->attributes[$key] = $factory->make($this->attributes, $options[0], $options[1], $options[2] ?? false);
            }
        }
    }

    public function newQuery(): ModelBuilder
    {
        return (new ModelBuilder($this, static::connection(static::class)->createQueryBuilder()))->with($this->with);
    }

    public function new(array $attributes = []): Model
    {
        if (!empty($attributes)) {
            $this->getIdentityMap()->inferRelationshipFromAttributes($this, $attributes);

            if (null === $model = $this->getIdentityMap()->get(static::class, $attributes[$this->getPrimaryKeyName()])) {
                $model = new static($attributes);

                $this->getIdentityMap()->add($model);
            }

            return $model;
        }

        return new static();
    }

    /**
     * Returns all attributes including virtual, excluding the internally allocated attributes
     *
     * @return array
     */
    public function getAttributes(): array
    {
        $attributes = $this->attributes;
        $ignore     = get_class_methods(self::class);

        foreach (get_class_methods($this) as $method) {
            $matches = [];

            if (!in_array($method, $ignore) && preg_match('/^get(?<property>[\w\d]+)Attribute/', $method, $matches)) {
                $prop = Str::snake($matches['property']);

                $attributes[$prop] = $this->{$method}($this->attributes[$prop] ?? null);
            }
        }

        return (new FilterGeneratedKeysFromCollection())($attributes);
    }

    /**
     * Returns the raw attribute without passing through mutators or relationships
     *
     * @param string $name
     *
     * @return mixed
     */
    public function getRawAttribute(string $name)
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * Get the requested attribute or relationship
     *
     * If a mutator is defined (getXxxxAttribute method), the attribute will be passed
     * through that first. If the attribute does not exist a virtual accessor will be
     * checked and return if there is one.
     *
     * Finally, if the relationship exists and has not been loaded, it will be at this
     * point.
     *
     * @param string $name
     *
     * @return mixed|null
     */
    public function getAttribute(string $name)
    {
        $mutator = $this->getAttributeMutator($name);

        // real attributes first
        if (array_key_exists($name, $this->attributes)) {
            if (method_exists($this, $mutator)) {
                return $this->{$mutator}($this->attributes[$name]);
            }

            return $this->attributes[$name];
        }

        // virtual attributes accessed via the mutator
        if (method_exists($this, $mutator)) {
            return $this->{$mutator}();
        }

        // ignore anything on the base Model class
        if (method_exists(self::class, $name)) {
            return null;
        }

        // fall into the relationship
        return $this->getRelationshipValue($name);
    }

    private function getAttributeMutator(string $name)
    {
        return sprintf('get%sAttribute', Str::studly($name));
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

    public function getExternalPrimaryKeyName(): ?string
    {
        return $this->externalPrimaryKey;
    }

    /**
     * Could return an object e.g. Uuid or string, depending on casting
     *
     * @return mixed|null
     */
    public function getExternalPrimaryKey()
    {
        return $this->attributes[$this->getExternalPrimaryKeyName()] ?? null;
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
        return $this->foreignKey ?? sprintf(
            '%s_%s', Str::snake(ClassHelpers::getObjectShortClassName($this), '_'), $this->getPrimaryKeyName()
        );
    }

    /**
     * Gets the owning side of the relationships key name
     *
     * @return string|null
     * @internal
     */
    public function getOwningKey(): ?string
    {
        return $this->owningKey;
    }

    public function jsonSerialize(): array
    {
        return $this->export()->toArray();
    }

    public function toArray(): array
    {
        return $this->export()->toArray();
    }

    public function toJson(int $options = 0): string
    {
        return $this->export()->toJson($options);
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
     * Define an inverse one-to-one or many relationship
     *
     * The table in this case will be the owning side of the relationship i.e. the originator
     * of the foreign key on the specified class. For example: a User has many Addresses,
     * the address table has a key: user_id linking the address to the user. This relationship
     * finds the user from the users table where the users.id = user_addresses.user_id.
     *
     * This will only associate a single model as the inverse side, nor will it update the
     * owner with this models association.
     *
     * @param string      $class
     * @param string|null $foreignKey
     * @param string|null $ownerKey
     * @param string|null $relation
     *
     * @return BelongsTo
     */
    protected function belongsTo(
        string $class, ?string $foreignKey = null, ?string $ownerKey = null, ?string $relation = null
    ): BelongsTo
    {
        /** @var Model $instance */
        $instance   = new $class();
        $relation   = $relation ?: ClassHelpers::getCallingMethod();
        $foreignKey = $foreignKey ?: sprintf('%s_%s', Str::snake($relation), $instance->getPrimaryKeyName());
        $ownerKey   = $ownerKey ?: $instance->getPrimaryKeyName();

        return new BelongsTo($instance->newQuery(), $this, $foreignKey, $ownerKey);
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

        $this->getIdentityMap()->registerAlias($this, $tableSourceKey);
        $this->getIdentityMap()->registerAlias($instance, $tableTargetKey);

        return new BelongsToMany(
            $instance->newQuery(), $this, $table, $tableSourceKey, $tableTargetKey, $sourceKey, $targetKey
        );
    }

    /**
     * Define a one to many relationship
     *
     * Here, the parent has many children, so a User can have many addresses.
     * The foreign key is the name of the parents key in the child's table.
     * local key is the child's primary key.
     *
     * indexBy allows a column on the child to be used as the key in the returned
     * collection. Note: if this is specified, then there can be only a single
     * instance of that key returned. This would usually be used on related objects
     * with a type where, the parent can only have one of each type e.g.: a contact
     * has a "type" field for: home, office, cell etc.
     *
     * @param string      $class
     * @param string|null $foreignKey
     * @param string|null $localKey
     * @param string|null $indexBy
     *
     * @return HasOneToMany
     */
    protected function hasMany(
        string $class, ?string $foreignKey = null, ?string $localKey = null, ?string $indexBy = null
    ): HasOneToMany
    {
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey   = $localKey ?: $this->getPrimaryKeyName();

        /** @var Model $instance */
        $instance = new $class();
        $instance->owningKey = $foreignKey;

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
     * @param string      $class
     * @param string|null $foreignKey
     * @param string|null $localKey
     *
     * @return HasOne
     */
    protected function hasOne(string $class, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey   = $localKey ?: $this->getPrimaryKeyName();

        /** @var Model $instance */
        $instance = new $class();
        $instance->owningKey = $foreignKey;

        return new HasOne(
            $instance->newQuery(), $this, $instance->getTable() . '.' . $foreignKey, $localKey
        );
    }
}
