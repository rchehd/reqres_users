<?php

declare(strict_types=1);

namespace Drupal\reqres_users\Event;

use Drupal\reqres_users\Dto\UserDto;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched after users are fetched from the Reqres API.
 */
class FilterReqresUsersEvent extends Event {

  public const EVENT_NAME = 'reqres_users.filter_users';

  /**
   * @param UserDto[] $users
   */
  public function __construct(private array $users) {}

  /**
   * @return UserDto[]
   */
  public function getUsers(): array {
    return $this->users;
  }

  /**
   * @param UserDto[] $users
   */
  public function setUsers(array $users): void {
    $this->users = $users;
  }

}
