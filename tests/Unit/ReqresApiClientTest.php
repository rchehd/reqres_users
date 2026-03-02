<?php

declare(strict_types=1);

namespace Drupal\Tests\reqres_users\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reqres_users\Api\ApiResult;
use Drupal\reqres_users\Api\CircuitBreaker;
use Drupal\reqres_users\Api\ReqresApiClient;
use Drupal\reqres_users\Dto\UserDto;
use Drupal\reqres_users\Event\FilterReqresUsersEvent;
use Drupal\reqres_users\Exception\ApiMalformedResponseException;
use Drupal\reqres_users\Exception\ApiNetworkException;
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

  /**
   * The HTTP client mock.
   *
   * @var \GuzzleHttp\ClientInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private ClientInterface&MockObject $httpClient;

  /**
   * The logger mock.
   *
   * @var \Psr\Log\LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private LoggerInterface&MockObject $logger;

  /**
   * The event dispatcher mock.
   *
   * @var \Symfony\Contracts\EventDispatcher\EventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private EventDispatcherInterface&MockObject $eventDispatcher;

  /**
   * The cache backend mock.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private CacheBackendInterface&MockObject $cache;

  /**
   * The state mock.
   *
   * @var \Drupal\Core\State\StateInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private StateInterface&MockObject $state;

  /**
   * The cache tags invalidator mock.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private CacheTagsInvalidatorInterface&MockObject $cacheTagsInvalidator;

  /**
   * The circuit breaker mock.
   *
   * @var \Drupal\reqres_users\Api\CircuitBreaker&\PHPUnit\Framework\MockObject\MockObject
   */
  private CircuitBreaker&MockObject $circuitBreaker;

  /**
   * The API client under test.
   *
   * @var \Drupal\reqres_users\Api\ReqresApiClient
   */
  private ReqresApiClient $apiClient;

  /**
   * A fixed API key used across tests.
   */
  private const string TEST_API_KEY = 'test-api-key-12345';

  /**
   * {@inheritdoc}
   */
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

    $this->circuitBreaker = $this->createMock(CircuitBreaker::class);
    $this->circuitBreaker->method('isOpen')->willReturn(FALSE);

    $this->apiClient = new ReqresApiClient(
      $this->httpClient,
      $this->logger,
      $this->eventDispatcher,
      $this->cache,
      $this->state,
      $this->cacheTagsInvalidator,
      $this->circuitBreaker,
    );
  }

  /**
   * Tests that a cached result is returned without calling the API.
   */
  public function testGetUsersReturnsCachedResultWithoutCallingApi(): void {
    $cachedResult = new ApiResult([], 12, 2);
    $cacheItem = $this->makeCacheItem($cachedResult);

    $this->cache->method('get')
      ->with('reqres_users:response:1:6')
      ->willReturn($cacheItem);

    $this->httpClient->expects($this->never())->method('request');

    $result = $this->apiClient->getUsers(1, 6, cache_ttl: 300);

    $this->assertSame([], $result->getUsers());
    $this->assertSame(12, $result->getTotal());
  }

  /**
   * Tests that a cache miss results in an API call and correct DTO mapping.
   */
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

    $this->assertCount(2, $result->getUsers());
    $this->assertSame(12, $result->getTotal());
    $this->assertSame(6, $result->getTotalPages());

    $first = $result->getUsers()[0];
    $this->assertInstanceOf(UserDto::class, $first);
    $this->assertSame(1, $first->id);
    $this->assertSame('george.bluth@reqres.in', $first->email);
    $this->assertSame('George', $first->firstName);
    $this->assertSame('Bluth', $first->lastName);
  }

  /**
   * Tests that the correct page and per_page query params are forwarded.
   */
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

  /**
   * Tests that the API response is written to cache when TTL is positive.
   */
  public function testGetUsersCachesResultWhenTtlIsPositive(): void {
    $this->cache->method('get')->willReturn(FALSE);
    $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
    $this->httpClient->method('request')
      ->willReturn(new Response(200, [], $this->fixtureJson()));

    $responseCached = FALSE;
    $this->cache
      ->expects($this->atLeastOnce())
      ->method('set')
      ->willReturnCallback(
        function (string $key, mixed $data, int $expire, array $tags) use (&$responseCached): void {
          if ($key === 'reqres_users:response:1:2') {
            $responseCached = TRUE;
            $this->assertInstanceOf(ApiResult::class, $data);
            $this->assertGreaterThan(0, $expire);
            $this->assertSame([ReqresApiClient::CACHE_TAG], $tags);
          }
        },
      );

    $this->apiClient->getUsers(1, 2, cache_ttl: 300);

    $this->assertTrue($responseCached, 'API response was not written to cache.');
  }

  /**
   * Tests that cache is completely skipped when TTL is zero.
   */
  public function testGetUsersSkipsCacheWhenTtlIsZero(): void {
    $this->cache->expects($this->never())->method('get');
    $this->cache->expects($this->never())->method('set');
    $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
    $this->httpClient->method('request')
      ->willReturn(new Response(200, [], $this->fixtureJson()));

    $this->apiClient->getUsers(1, 2, cache_ttl: 0);
  }

  /**
   * Tests that the cache tag is invalidated when the data hash changes.
   */
  public function testGetUsersInvalidatesCacheTagWhenDataHashChanges(): void {
    $this->cache->method('get')
      ->willReturnCallback(function (string $key): object|false {
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
   * Tests that the cache tag is NOT invalidated when the data is unchanged.
   *
   * @throws \JsonException
   */
  public function testGetUsersDoesNotInvalidateCacheTagWhenDataIsUnchanged(): void {
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

  /**
   * Tests that the circuit breaker blocks the request and throws.
   */
  public function testGetUsersThrowsNetworkExceptionWhenCircuitIsOpen(): void {
    $this->cache->method('get')->willReturn(FALSE);
    $this->circuitBreaker->method('isOpen')->willReturn(TRUE);
    $this->httpClient->expects($this->never())->method('request');

    $this->expectException(ApiNetworkException::class);

    $this->apiClient->getUsers(1, 6, cache_ttl: 300);
  }

  /**
   * Tests that ApiNetworkException is thrown after all retry attempts fail.
   */
  public function testGetUsersThrowsNetworkExceptionAfterAllRetriesFail(): void {
    $this->cache->method('get')->willReturn(FALSE);

    $this->httpClient
      ->expects($this->exactly(3))
      ->method('request')
      ->willThrowException(new TransferException('Connection refused'));

    $this->circuitBreaker
      ->expects($this->once())
      ->method('recordFailure');

    $this->expectException(ApiNetworkException::class);

    $this->apiClient->getUsers(1, 6, cache_ttl: 300);
  }

  /**
   * Tests that ApiMalformedResponseException is thrown on invalid JSON.
   */
  public function testGetUsersThrowsMalformedResponseExceptionOnBadJson(): void {
    $this->cache->method('get')->willReturn(FALSE);

    $this->httpClient
      ->expects($this->once())
      ->method('request')
      ->willReturn(new Response(200, [], 'not valid json {'));

    $this->circuitBreaker
      ->expects($this->once())
      ->method('recordFailure');

    $this->expectException(ApiMalformedResponseException::class);

    $this->apiClient->getUsers(1, 6, cache_ttl: 300);
  }

  /**
   * Tests that ApiMalformedResponseException is thrown on unexpected structure.
   */
  public function testGetUsersThrowsMalformedResponseExceptionOnUnexpectedStructure(): void {
    $this->cache->method('get')->willReturn(FALSE);

    $this->httpClient
      ->method('request')
      ->willReturn(new Response(200, [], '{"unexpected": true}'));

    $this->circuitBreaker
      ->expects($this->once())
      ->method('recordFailure');

    $this->expectException(ApiMalformedResponseException::class);

    $this->apiClient->getUsers(1, 6, cache_ttl: 300);
  }

  /**
   * Tests that the client retries on transient errors and succeeds on the third attempt.
   */
  public function testGetUsersRetriesOnTransientNetworkError(): void {
    $this->cache->method('get')->willReturn(FALSE);
    $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

    $callCount = 0;
    $fixture = $this->fixtureJson();
    $this->httpClient
      ->expects($this->exactly(3))
      ->method('request')
      ->willReturnCallback(
        static function () use (&$callCount, $fixture): Response {
          $callCount++;
          if ($callCount < 3) {
            throw new TransferException('Timeout');
          }
          return new Response(200, [], $fixture);
        },
      );

    $this->circuitBreaker->expects($this->once())->method('recordSuccess');
    $this->circuitBreaker->expects($this->never())->method('recordFailure');

    $result = $this->apiClient->getUsers(1, 2, cache_ttl: 0);

    $this->assertCount(2, $result->getUsers());
  }

  /**
   * Tests that an event subscriber can filter the returned user list.
   */
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

    $this->assertSame([], $result->getUsers());
    $this->assertSame(12, $result->getTotal());
  }

  /**
   * Tests that the API key is sent as an x-api-key request header.
   */
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

  /**
   * Tests that the correct event name is used when dispatching the filter event.
   */
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

  /**
   * Creates a minimal cache item object with the given data payload.
   *
   * @param mixed $data
   *   The data to store in the cache item.
   *
   * @return object
   *   A stdClass with a 'data' property.
   */
  private function makeCacheItem(mixed $data): object {
    $item = new \stdClass();
    $item->data = $data;
    return $item;
  }

  /**
   * Returns a JSON string matching the Reqres API response fixture.
   *
   * @return string
   *   A JSON-encoded fixture.
   *
   * @throws \JsonException
   */
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
