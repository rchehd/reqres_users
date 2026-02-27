<?php

declare(strict_types=1);

namespace Drupal\reqres_users\Api;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reqres_users\Dto\UserDto;
use Drupal\reqres_users\Event\FilterReqresUsersEvent;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Implementation of the ReqresApiClientInterface for interacting with the Reqres API.
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
   *
   * Stored without the cache tag so they survive tag-based invalidation and
   * can still detect future data changes.
   */
  private const string HASH_PREFIX = 'reqres_users:data_hash:';

  public function __construct(
    protected ClientInterface $httpClient,
    protected LoggerInterface $logger,
    protected EventDispatcherInterface $eventDispatcher,
    protected CacheBackendInterface $cache,
    protected StateInterface $state,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getUsers(int $page, int $per_page, int $cache_ttl = 300): array {
    $response_key = self::RESPONSE_PREFIX . $page . ':' . $per_page;

    if ($cache_ttl > 0) {
      $cached = $this->cache->get($response_key);
      if ($cached !== FALSE) {
        return $cached->data;
      }
    }

    try {
      $api_key = (string) $this->state->get(self::STATE_KEY, '');

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
        $this->logger->error('Reqres API returned an unexpected response structure.');
        return ['users' => [], 'total' => 0, 'total_pages' => 0];
      }

      $users = array_map(
        static fn(array $item): UserDto => UserDto::fromApiData($item),
        $data['data'],
      );

      $event = new FilterReqresUsersEvent($users);
      /** @var FilterReqresUsersEvent $event */
      $event = $this->eventDispatcher->dispatch($event, FilterReqresUsersEvent::EVENT_NAME);

      $result = [
        'users' => $event->getUsers(),
        'total' => (int) $data['total'],
        'total_pages' => (int) $data['total_pages'],
      ];

      if ($cache_ttl > 0) {
        $this->handleCacheInvalidation($page, $per_page, $data['data']);
        $this->cache->set(
          $response_key,
          $result,
          time() + $cache_ttl,
          [self::CACHE_TAG],
        );
      }

      return $result;
    }
    catch (\JsonException $e) {
      $this->logger->error('Reqres API returned invalid JSON: @message', [
        '@message' => $e->getMessage(),
      ]);
      return ['users' => [], 'total' => 0, 'total_pages' => 0];
    }
    catch (GuzzleException $e) {
      $this->logger->error('Reqres API request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return ['users' => [], 'total' => 0, 'total_pages' => 0];
    }
  }

  /**
   * Compares the new API payload hash with the stored one.
   *
   * If the data has changed, invalidates the 'reqres_users' cache tag so that
   * all block renders depending on it are immediately busted. The hash entry
   * is stored permanently without the cache tag so it survives tag-based
   * invalidation and can detect the next change.
   *
   * Only called when cache_ttl > 0 (i.e. caching is enabled).
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
