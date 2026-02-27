<?php

declare(strict_types=1);

namespace Drupal\reqres_users\Api;

/**
 * Interface for interacting with the Reqres API.
 */
interface ReqresApiClientInterface {

  /**
   * Fetches a page of users from the Reqres API.
   *
   * @param int $page
   *   1-based page number (Reqres API convention).
   * @param int $per_page
   *   Number of items to request per page.
   * @param int $cache_ttl
   *   Seconds to cache the API response. 0 disables caching.
   *
   * @return array{users: \Drupal\reqres_users\Dto\UserDto[], total: int}
   *   An associative array with:
   *   - users: the (possibly filtered) list of UserDto objects.
   *   - total: the unfiltered total number of users reported by the API.
   */
  public function getUsers(int $page, int $per_page, int $cache_ttl = 300): array;

}
