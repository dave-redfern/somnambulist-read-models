<?php declare(strict_types=1);

namespace Somnambulist\ReadModels\Relationships;

use Somnambulist\Collection\MutableCollection as Collection;
use Somnambulist\ReadModels\Model;
use Somnambulist\ReadModels\ModelBuilder;

/**
 * Class HasOneOrMany
 *
 * @package    Somnambulist\ReadModels\Relationships
 * @subpackage Somnambulist\ReadModels\Relationships\HasOneOrMany
 */
abstract class HasOneOrMany extends AbstractRelationship
{

    protected string $foreignKey;
    protected string $localKey;

    public function __construct(ModelBuilder $builder, Model $parent, string $foreignKey, string $localKey)
    {
        $this->foreignKey = $foreignKey;
        $this->localKey   = $localKey;

        parent::__construct($builder, $parent);
    }

    public function addConstraints(Collection $models): AbstractRelationship
    {
        $this->query = $this->query->whereIn(
            $this->foreignKey, $models->extract($this->localKey)->unique()->toArray()
        );

        return $this;
    }
}
