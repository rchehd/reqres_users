<?php

declare(strict_types=1);

namespace Drupal\reqres_users\Exception;

/**
 * Thrown when the API response is invalid JSON or has an unexpected structure.
 */
class ApiMalformedResponseException extends ApiException {}
