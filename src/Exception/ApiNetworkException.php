<?php

declare(strict_types=1);

namespace Drupal\reqres_users\Exception;

/**
 * Thrown when the HTTP transport fails after all retries or circuit is open.
 */
class ApiNetworkException extends ApiException {}
