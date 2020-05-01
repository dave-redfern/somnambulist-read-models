<?php

declare(strict_types=1);

namespace Somnambulist\ReadModels\Tests\Relationships;

use PHPUnit\Framework\TestCase;
use Somnambulist\ReadModels\Tests\Stubs\Models\Role;

/**
 * Class RelationshipViaExternalKeyTest
 *
 * @package    Somnambulist\ReadModels\Tests\Relationships
 * @subpackage Somnambulist\ReadModels\Tests\Relationships\RelationshipViaExternalKeyTest
 * @group relationships
 */
class RelationshipWithOrderByTest extends TestCase
{

    /**
     * @group relationship-order-by
     */
    public function testRelationshipCanBeOrdered()
    {
        $role = Role::with('permissions2')->limit(1)->fetchFirstOrFail();

        $this->assertInstanceOf(Role::class, $role);

        $this->assertLessThan($role->permissions2->first()->id(), $role->permissions2->last()->id());
    }

    /**
     * @group relationship-order-by
     */
    public function testRelationshipCanBeOrderedAndFiltered()
    {
        $role = Role::query()->limit(1)->fetchFirstOrFail();

        $results = $role->permissions2()->whereColumn('name', 'LIKE', 'search.relation.%')->fetch();

        $this->assertLessThan($role->permissions->count(), $results->count());
    }
}
