<?php

declare(strict_types=1);

namespace Drupal\reqres_users\Api;

/**
 * Immutable value object representing a paginated API result.
 */
class ApiResult {

  /**
   * Constructs an ApiResult instance.
   *
   * @param \Drupal\reqres_users\Dto\UserDto[] $users
   *   The list of user DTOs.
   * @param int $total
   *   The total number of users reported by the API.
   * @param int $totalPages
   *   The total number of pages reported by the API.
   */
  public function __construct(
    private readonly array $users,
    private readonly int $total,
    private readonly int $totalPages,
  ) {}

  /**
   * Returns an empty result with no users and zero totals.
   *
   * @return self
   *   An empty ApiResult.
   */
  public static function empty(): self {
    return new self([], 0, 0);
  }

  /**
   * Returns the list of user DTOs.
   *
   * @return \Drupal\reqres_users\Dto\UserDto[]
   *   The user DTO list.
   */
  public function getUsers(): array {
    return $this->users;
  }

  /**
   * Returns the total number of users reported by the API.
   *
   * @return int
   *   Total user count.
   */
  public function getTotal(): int {
    return $this->total;
  }

  /**
   * Returns the total number of pages reported by the API.
   *
   * @return int
   *   Total page count.
   */
  public function getTotalPages(): int {
    return $this->totalPages;
  }

}
