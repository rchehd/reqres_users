<?php

declare(strict_types=1);

namespace Drupal\reqres_users\Api;

use Drupal\reqres_users\Contract\UserProviderInterface;

/**
 * Interface for interacting with the Reqres API.
 */
interface ReqresApiClientInterface extends UserProviderInterface {}
