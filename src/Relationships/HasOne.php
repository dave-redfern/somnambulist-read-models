<?php declare(strict_types=1);

namespace Somnambulist\Components\ReadModels\Relationships;

use Somnambulist\Collection\Contracts\Collection;
use Somnambulist\Components\ReadModels\Manager;
use Somnambulist\Components\ReadModels\Model;
use Somnambulist\Components\ReadModels\ModelBuilder;
use function get_class;

/**
 * Class HasOne
 *
 * @package    Somnambulist\Components\ReadModels\Relationships
 * @subpackage Somnambulist\Components\ReadModels\Relationships\HasOne
 */
class HasOne extends HasOneOrMany
{

    private bool $nullOnNotFound;

    public function __construct(ModelBuilder $builder, Model $parent, string $foreignKey, string $localKey, bool $nullOnNotFound = true)
    {
        parent::__construct($builder, $parent, $foreignKey, $localKey);

        $this->nullOnNotFound = $nullOnNotFound;
    }

    public function addRelationshipResultsToModels(Collection $models, string $relationship): AbstractRelationship
    {
        if (count($this->getQueryBuilder()->getQueryPart('select')) > 0 && !$this->hasSelectExpression($this->foreignKey)) {
            $this->query->select($this->foreignKey);
        }

        $this->fetch();

        $map = Manager::instance()->map();

        $models->each(function (Model $parent) use ($relationship, $map) {
            $ids = $map->getRelatedIdentitiesFor($parent, $class = get_class($this->related));

            $related = $map->all($class, $ids)[0] ?? ($this->nullOnNotFound ? null : $this->related->new());

            $parent->setRelationshipValue($relationship, $related);
        });

        return $this;
    }
}
