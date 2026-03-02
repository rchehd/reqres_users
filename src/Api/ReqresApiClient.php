<?php

declare(strict_types=1);

namespace Drupal\reqres_users\Api;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reqres_users\Dto\UserDto;
use Drupal\reqres_users\Event\FilterReqresUsersEvent;
use Drupal\reqres_users\Exception\ApiMalformedResponseException;
use Drupal\reqres_users\Exception\ApiNetworkException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Provides Reqres API interaction, caching, event dispatching, and retry logic.
 */
class ReqresApiClient implements ReqresApiClientInterface {

  /**
   * Endpoint URL for the Reqres users resource.
   */
  private const string BASE_URL = 'https://reqres.in/api/users';

  /**
   * State key under which the API key is persisted.
   */
  public const string STATE_KEY = 'reqres_users.api_key';

  /**
   * Timeout for API requests in seconds.
   */
  private const int TIMEOUT = 5;

  /**
   * Cache tag attached to all cached API responses and block renders.
   */
  public const string CACHE_TAG = 'reqres_users';

  /**
   * Cache key prefix for API response results.
   */
  private const string RESPONSE_PREFIX = 'reqres_users:response:';

  /**
   * Cache key prefix for per-page data hashes.
   */
  private const string HASH_PREFIX = 'reqres_users:data_hash:';

  /**
   * Maximum number of HTTP request attempts before giving up.
   */
  private const int MAX_ATTEMPTS = 3;

  /**
   * Base retry delay in microseconds (200 ms); doubled on each attempt.
   */
  private const int RETRY_BASE_DELAY_US = 200000;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected LoggerInterface $logger,
    protected EventDispatcherInterface $eventDispatcher,
    protected CacheBackendInterface $cache,
    protected StateInterface $state,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected CircuitBreaker $circuitBreaker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getUsers(int $page, int $per_page, int $cache_ttl = 300): ApiResult {
    $response_key = self::RESPONSE_PREFIX . $page . ':' . $per_page;

    if ($cache_ttl > 0) {
      $cached = $this->cache->get($response_key);
      if ($cached !== FALSE) {
        return $cached->data;
      }
    }

    if ($this->circuitBreaker->isOpen()) {
      $this->logger->warning('Reqres API circuit breaker is open; skipping request.');
      throw new ApiNetworkException('Reqres API is temporarily unavailable.');
    }

    $data = $this->fetchWithRetry($page, $per_page);

    /** @var array<int, array<string, mixed>> $items */
    $items = $data['data'];
    $users = array_map(
      static fn(array $item): UserDto => UserDto::fromApiData($item),
      $items,
    );

    $event = new FilterReqresUsersEvent($users);
    /** @var \Drupal\reqres_users\Event\FilterReqresUsersEvent $event */
    $event = $this->eventDispatcher->dispatch($event, FilterReqresUsersEvent::EVENT_NAME);

    $result = new ApiResult(
      $event->getUsers(),
      (int) $data['total'],
      (int) $data['total_pages'],
    );

    if ($cache_ttl > 0) {
      $this->handleCacheInvalidation($page, $per_page, $items);
      $this->cache->set(
        $response_key,
        $result,
        time() + $cache_ttl,
        [self::CACHE_TAG],
      );
    }

    $this->circuitBreaker->recordSuccess();
    return $result;
  }

  /**
   * Fetches raw API data with exponential-backoff retries on network errors.
   *
   * JSON decode errors and unexpected response structures are thrown
   * immediately as ApiMalformedResponseException without retrying.
   *
   * @param int $page
   *   The 1-based page number.
   * @param int $per_page
   *   Items per page.
   *
   * @return array<string, mixed>
   *   The decoded API response body.
   *
   * @throws \Drupal\reqres_users\Exception\ApiNetworkException
   *   After all retry attempts are exhausted.
   * @throws \Drupal\reqres_users\Exception\ApiMalformedResponseException
   *   Immediately on invalid JSON or an unexpected response structure.
   */
  private function fetchWithRetry(int $page, int $per_page): array {
    $api_key = (string) $this->state->get(self::STATE_KEY, '');
    $last_exception = NULL;

    for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
      try {
        $response = $this->httpClient->request('GET', self::BASE_URL, [
          'timeout' => self::TIMEOUT,
          'headers' => [
            'x-api-key' => $api_key,
          ],
          'query' => [
            'page' => $page,
            'per_page' => $per_page,
          ],
        ]);

        $data = json_decode((string) $response->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);

        if (!is_array($data) || !isset($data['data'], $data['total'], $data['total_pages'])) {
          $this->circuitBreaker->recordFailure();
          $this->logger->error('Reqres API returned an unexpected response structure.');
          throw new ApiMalformedResponseException('Reqres API returned an unexpected response structure.');
        }

        return $data;
      }
      catch (\JsonException $e) {
        $this->circuitBreaker->recordFailure();
        $this->logger->error('Reqres API returned invalid JSON: @message', [
          '@message' => $e->getMessage(),
        ]);
        throw new ApiMalformedResponseException('Reqres API returned invalid JSON.', 0, $e);
      }
      catch (GuzzleException $e) {
        $this->logger->error(
          'Reqres API request failed (attempt @attempt of @max): @message',
          [
            '@attempt' => $attempt,
            '@max' => self::MAX_ATTEMPTS,
            'page' => $page,
            'per_page' => $per_page,
            '@message' => $e->getMessage(),
          ],
        );
        $last_exception = $e;
        if ($attempt < self::MAX_ATTEMPTS) {
          usleep(self::RETRY_BASE_DELAY_US * (int) (2 ** ($attempt - 1)));
        }
      }
    }

    $this->circuitBreaker->recordFailure();
    throw new ApiNetworkException(
      'Reqres API request failed after ' . self::MAX_ATTEMPTS . ' attempts.',
      0,
      $last_exception,
    );
  }

  /**
   * Compares the new API payload hash with the stored one.
   *
   * @param int $page
   *   Current API page.
   * @param int $per_page
   *   Items per page.
   * @param array<int, array<string, mixed>> $raw_items
   *   The raw 'data' array from the API response, before DTO mapping.
   *
   * @throws \JsonException
   */
  private function handleCacheInvalidation(int $page, int $per_page, array $raw_items): void {
    $new_hash = md5((string) json_encode($raw_items, JSON_THROW_ON_ERROR));
    $hash_key = self::HASH_PREFIX . $page . ':' . $per_page;

    $previous_entry = $this->cache->get($hash_key);

    if ($previous_entry !== FALSE && $previous_entry->data !== $new_hash) {
      $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
    }

    // Store without the cache tag so this entry survives tag invalidation.
    $this->cache->set($hash_key, $new_hash, CacheBackendInterface::CACHE_PERMANENT);
  }

}
