<?php

declare(strict_types=1);

namespace Somnambulist\ReadModels\Tests;

use Doctrine\DBAL\Query\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Somnambulist\ReadModels\Model;
use Somnambulist\ReadModels\Relationships\BelongsTo;
use Somnambulist\ReadModels\Tests\Stubs\Models\User;
use Somnambulist\ReadModels\Tests\Stubs\Models\UserAddress;
use Somnambulist\ReadModels\Tests\Stubs\Models\UserProfile;

/**
 * Class BelongsToTest
 *
 * @package    Somnambulist\ReadModels\Tests
 * @subpackage Somnambulist\ReadModels\Tests\BelongsToTest
 * @group relationships
 * @group relationships-belongs-to
 */
class BelongsToTest extends TestCase
{

    public function testBelongsTo()
    {
        $model = new UserAddress();
        $rel = $model->getRelationship('user');

        $this->assertInstanceOf(BelongsTo::class, $rel);

    }

    public function testPassThroughMethods()
    {
        $user = new UserAddress();
        $rel = $user->getRelationship('user');

        $this->assertInstanceOf(Model::class, $rel->getModel());
        $this->assertInstanceOf(QueryBuilder::class, $rel->getQueryBuilder());
    }

    /**
     * @group cur
     */
    public function testReturnsEmptyObjectIfSetOnRelationship()
    {
        $ua = new UserAddress();

        $this->assertInstanceOf(User::class, $ua->fixed_user);
        $this->assertNull($ua->fixed_user->uuid);
    }
}
