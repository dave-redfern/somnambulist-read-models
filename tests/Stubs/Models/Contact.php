<?php

declare(strict_types=1);

namespace Somnambulist\ReadModels\Tests\Stubs\Models;

use Somnambulist\Domain\Entities\Types\Identity\EmailAddress;
use Somnambulist\Domain\Entities\Types\PhoneNumber;

/**
 * Class Contact
 *
 * @package    Somnambulist\ReadModels\Tests\Stubs\Models
 * @subpackage Somnambulist\ReadModels\Tests\Stubs\Models\Contact
 */
class Contact
{

    private string $name;
    private ?PhoneNumber $phone;
    private ?EmailAddress $email;

    public function __construct(string $name, ?PhoneNumber $phone, ?EmailAddress $email)
    {
        $this->name  = $name;
        $this->phone = $phone;
        $this->email = $email;
    }

    public function __get($name)
    {
        return $this->{$name} ?? null;
    }

    public function getName()
    {
        return $this->name;
    }

    public function phone(): ?PhoneNumber
    {
        return $this->phone;
    }

    public function email(): ?EmailAddress
    {
        return $this->email;
    }
}
