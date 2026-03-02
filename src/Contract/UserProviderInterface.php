<?php

declare(strict_types=1);

namespace Drupal\reqres_users\Contract;

use Drupal\reqres_users\Api\ApiResult;

/**
 * Domain-level contract for fetching a page of users.
 */
interface UserProviderInterface {

  /**
   * Fetches a page of users.
   *
   * @param int $page
   *   1-based page number (Reqres API convention).
   * @param int $per_page
   *   Number of items to request per page.
   * @param int $cache_ttl
   *   Seconds to cache the result. 0 disables caching.
   *
   * @return \Drupal\reqres_users\Api\ApiResult
   *   The result containing the user list and pagination metadata.
   *
   * @throws \Drupal\reqres_users\Exception\ApiException
   *   When the API request fails or returns an unexpected response.
   */
  public function getUsers(int $page, int $per_page, int $cache_ttl = 300): ApiResult;

}
