<?php

declare(strict_types=1);

namespace Drupal\reqres_users\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched after users are fetched from the Reqres API.
 */
class FilterReqresUsersEvent extends Event {

  /**
   * The event name.
   */
  public const EVENT_NAME = 'reqres_users.filter_users';

  /**
   * Constructs a new FilterReqresUsersEvent.
   *
   * @param \Drupal\reqres_users\Dto\UserDto[] $users
   *   Initial list of UserDto objects to be filtered.
   */
  public function __construct(private array $users) {}

  /**
   * Returns the current list of users.
   *
   * @return \Drupal\reqres_users\Dto\UserDto[]
   *   The list of UserDto objects.
   */
  public function getUsers(): array {
    return $this->users;
  }

  /**
   * Replaces the user list.
   *
   * @param \Drupal\reqres_users\Dto\UserDto[] $users
   *   Replacement list of UserDto objects.
   */
  public function setUsers(array $users): void {
    $this->users = $users;
  }

}
