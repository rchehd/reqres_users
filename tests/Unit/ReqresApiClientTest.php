<?php

declare(strict_types=1);

namespace Drupal\Tests\reqres_users\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reqres_users\Api\ReqresApiClient;
use Drupal\reqres_users\Dto\UserDto;
use Drupal\reqres_users\Event\FilterReqresUsersEvent;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @covers \Drupal\reqres_users\Api\ReqresApiClient
 * @group reqres_users
 */
final class ReqresApiClientTest extends TestCase {

  private ClientInterface&MockObject $httpClient;

  private LoggerInterface&MockObject $logger;

  private EventDispatcherInterface&MockObject $eventDispatcher;

  private CacheBackendInterface&MockObject $cache;

  private StateInterface&MockObject $state;

  private CacheTagsInvalidatorInterface&MockObject $cacheTagsInvalidator;

  private ReqresApiClient $apiClient;

  private const string TEST_API_KEY = 'test-api-key-12345';

  protected function setUp(): void {
    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
    $this->cache = $this->createMock(CacheBackendInterface::class);
    $this->cacheTagsInvalidator = $this->createMock(CacheTagsInvalidatorInterface::class);

    $this->state = $this->createMock(StateInterface::class);
    $this->state->method('get')
      ->with(ReqresApiClient::STATE_KEY, '')
      ->willReturn(self::TEST_API_KEY);

    $this->apiClient = new ReqresApiClient(
      $this->httpClient,
      $this->logger,
      $this->eventDispatcher,
      $this->cache,
      $this->state,
      $this->cacheTagsInvalidator,
    );
  }

  // -------------------------------------------------------------------------
  // Cache hit
  // -------------------------------------------------------------------------

  public function testGetUsersReturnsCachedResultWithoutCallingApi(): void {
    $cachedData = ['users' => [], 'total' => 12];
    $cacheItem = $this->makeCacheItem($cachedData);

    $this->cache->method('get')
      ->with('reqres_users:response:1:6')
      ->willReturn($cacheItem);

    // HTTP client must NOT be called when cache is warm.
    $this->httpClient->expects($this->never())->method('request');

    $result = $this->apiClient->getUsers(1, 6, cache_ttl: 300);

    $this->assertSame($cachedData, $result);
  }

  // -------------------------------------------------------------------------
  // Cache miss — happy path
  // -------------------------------------------------------------------------

  public function testGetUsersMapsResponseToDtos(): void {
    $this->cache->method('get')->willReturn(FALSE);
    $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

    $this->httpClient
      ->expects($this->once())
      ->method('request')
      ->with('GET', 'https://reqres.in/api/users', $this->callback(
        static fn(array $opts): bool => isset($opts['query']) && isset($opts['headers']['x-api-key']),
      ))
      ->willReturn(new Response(200, [], $this->fixtureJson()));

    $result = $this->apiClient->getUsers(1, 2, cache_ttl: 300);

    $this->assertCount(2, $result['users']);
    $this->assertSame(12, $result['total']);
    $this->assertSame(6, $result['total_pages']);

    $first = $result['users'][0];
    $this->assertInstanceOf(UserDto::class, $first);
    $this->assertSame(1, $first->id);
    $this->assertSame('george.bluth@reqres.in', $first->email);
    $this->assertSame('George', $first->firstName);
    $this->assertSame('Bluth', $first->lastName);
  }

  public function testGetUsersForwardsCorrectPageAndPerPageToApi(): void {
    $this->cache->method('get')->willReturn(FALSE);
    $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

    $this->httpClient
      ->expects($this->once())
      ->method('request')
      ->with('GET', 'https://reqres.in/api/users', $this->callback(
        static fn(array $options): bool =>
          ($options['query']['page'] ?? NULL) === 3
          && ($options['query']['per_page'] ?? NULL) === 10,
      ))
      ->willReturn(new Response(200, [], $this->fixtureJson()));

    $this->apiClient->getUsers(3, 10, cache_ttl: 300);
  }

  public function testGetUsersCachesResultWhenTtlIsPositive(): void {
    $this->cache->method('get')->willReturn(FALSE);
    $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
    $this->httpClient->method('request')
      ->willReturn(new Response(200, [], $this->fixtureJson()));

    // Both the response and the hash are persisted; verify the response entry.
    $responseCached = FALSE;
    $this->cache
      ->expects($this->atLeastOnce())
      ->method('set')
      ->willReturnCallback(
        function (string $key, mixed $data, int $expire, array $tags) use (&$responseCached): void {
          if ($key === 'reqres_users:response:1:2') {
            $responseCached = TRUE;
            $this->assertIsArray($data);
            $this->assertGreaterThan(0, $expire);
            $this->assertSame([ReqresApiClient::CACHE_TAG], $tags);
          }
        },
      );

    $this->apiClient->getUsers(1, 2, cache_ttl: 300);

    $this->assertTrue($responseCached, 'API response was not written to cache.');
  }

  public function testGetUsersSkipsCacheWhenTtlIsZero(): void {
    // Neither cache::get nor cache::set should be called when TTL = 0.
    $this->cache->expects($this->never())->method('get');
    $this->cache->expects($this->never())->method('set');
    $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
    $this->httpClient->method('request')
      ->willReturn(new Response(200, [], $this->fixtureJson()));

    $this->apiClient->getUsers(1, 2, cache_ttl: 0);
  }

  // -------------------------------------------------------------------------
  // Hash-based cache invalidation
  // -------------------------------------------------------------------------

  public function testGetUsersInvalidatesCacheTagWhenDataHashChanges(): void {
    $this->cache->method('get')
      ->willReturnCallback(function (string $key): object|false {
        // Simulate: API response cache miss, but old hash exists.
        if (str_starts_with($key, 'reqres_users:response:')) {
          return FALSE;
        }
        if (str_starts_with($key, 'reqres_users:data_hash:')) {
          return $this->makeCacheItem('old_hash_that_wont_match');
        }
        return FALSE;
      });

    $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
    $this->httpClient->method('request')
      ->willReturn(new Response(200, [], $this->fixtureJson()));

    $this->cacheTagsInvalidator
      ->expects($this->once())
      ->method('invalidateTags')
      ->with([ReqresApiClient::CACHE_TAG]);

    $this->apiClient->getUsers(1, 2, cache_ttl: 300);
  }

  /**
   * @throws \JsonException
   */
  public function testGetUsersDoesNotInvalidateCacheTagWhenDataIsUnchanged(): void {
    // Pre-compute the hash for the fixture so they match.
    $fixtureItems = json_decode(
      $this->fixtureJson(),
      TRUE,
      512,
      JSON_THROW_ON_ERROR
    )['data'];

    $expectedHash = md5((string) json_encode($fixtureItems, JSON_THROW_ON_ERROR));

    $this->cache->method('get')
      ->willReturnCallback(function (string $key) use ($expectedHash): object|false {
        if (str_starts_with($key, 'reqres_users:response:')) {
          return FALSE;
        }
        if (str_starts_with($key, 'reqres_users:data_hash:')) {
          return $this->makeCacheItem($expectedHash);
        }
        return FALSE;
      });

    $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
    $this->httpClient->method('request')
      ->willReturn(new Response(200, [], $this->fixtureJson()));

    $this->cacheTagsInvalidator->expects($this->never())->method('invalidateTags');

    $this->apiClient->getUsers(1, 2, cache_ttl: 300);
  }

  // -------------------------------------------------------------------------
  // Error handling
  // -------------------------------------------------------------------------

  public function testGetUsersReturnsEmptyArrayOnGuzzleException(): void {
    $this->cache->method('get')->willReturn(FALSE);

    $this->httpClient
      ->expects($this->once())
      ->method('request')
      ->willThrowException(new TransferException('Connection refused'));

    $this->logger->expects($this->once())->method('error');

    $result = $this->apiClient->getUsers(1, 6, cache_ttl: 300);

    $this->assertSame([], $result['users']);
    $this->assertSame(0, $result['total']);
    $this->assertSame(0, $result['total_pages']);
  }

  public function testGetUsersReturnsEmptyArrayOnMalformedResponse(): void {
    $this->cache->method('get')->willReturn(FALSE);

    $this->httpClient
      ->method('request')
      ->willReturn(new Response(200, [], '{"unexpected": true}'));

    $this->logger->expects($this->once())->method('error');

    $result = $this->apiClient->getUsers(1, 6, cache_ttl: 300);

    $this->assertSame([], $result['users']);
    $this->assertSame(0, $result['total']);
    $this->assertSame(0, $result['total_pages']);
  }

  // -------------------------------------------------------------------------
  // Event / extension point
  // -------------------------------------------------------------------------

  public function testGetUsersAppliesEventFilter(): void {
    $this->cache->method('get')->willReturn(FALSE);
    $this->httpClient->method('request')
      ->willReturn(new Response(200, [], $this->fixtureJson()));

    // Subscriber removes all users.
    $this->eventDispatcher
      ->method('dispatch')
      ->willReturnCallback(static function (FilterReqresUsersEvent $event): FilterReqresUsersEvent {
        $event->setUsers([]);
        return $event;
      });

    $result = $this->apiClient->getUsers(1, 2, cache_ttl: 300);

    $this->assertSame([], $result['users']);
    // Total is still the API-reported value, unaffected by filtering.
    $this->assertSame(12, $result['total']);
  }

  public function testGetUsersSendsApiKeyHeader(): void {
    $this->cache->method('get')->willReturn(FALSE);
    $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

    $this->httpClient
      ->expects($this->once())
      ->method('request')
      ->with('GET', 'https://reqres.in/api/users', $this->callback(
        static fn(array $opts): bool =>
          ($opts['headers']['x-api-key'] ?? NULL) === self::TEST_API_KEY,
      ))
      ->willReturn(new Response(200, [], $this->fixtureJson()));

    $this->apiClient->getUsers(1, 2, cache_ttl: 0);
  }

  public function testGetUsersDispatchesCorrectEventName(): void {
    $this->cache->method('get')->willReturn(FALSE);
    $this->httpClient->method('request')
      ->willReturn(new Response(200, [], $this->fixtureJson()));

    $this->eventDispatcher
      ->expects($this->once())
      ->method('dispatch')
      ->with(
        $this->isInstanceOf(FilterReqresUsersEvent::class),
        FilterReqresUsersEvent::EVENT_NAME,
      )
      ->willReturnArgument(0);

    $this->apiClient->getUsers(1, 2, cache_ttl: 300);
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  private function makeCacheItem(mixed $data): object {
    $item = new \stdClass();
    $item->data = $data;
    return $item;
  }

  private function fixtureJson(): string {
    return (string) json_encode([
      'page' => 1,
      'per_page' => 2,
      'total' => 12,
      'total_pages' => 6,
      'data' => [
        [
          'id' => 1,
          'email' => 'george.bluth@reqres.in',
          'first_name' => 'George',
          'last_name' => 'Bluth',
          'avatar' => 'https://reqres.in/img/faces/1-image.jpg',
        ],
        [
          'id' => 2,
          'email' => 'janet.weaver@reqres.in',
          'first_name' => 'Janet',
          'last_name' => 'Weaver',
          'avatar' => 'https://reqres.in/img/faces/2-image.jpg',
        ],
      ],
    ], JSON_THROW_ON_ERROR);
  }

}
