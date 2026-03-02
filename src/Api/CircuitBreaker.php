<?php

declare(strict_types=1);

namespace Drupal\reqres_users\Api;

use Drupal\Core\State\StateInterface;

/**
 * Lightweight circuit breaker backed by Drupal State.
 */
class CircuitBreaker {

  /**
   * State key used to persist the circuit state array.
   */
  private const string STATE_KEY = 'reqres_users.circuit_state';

  /**
   * Number of consecutive failures required to open the circuit.
   */
  private const int FAILURE_THRESHOLD = 5;

  /**
   * Seconds to wait before a half-open probe is allowed through.
   */
  private const int COOLDOWN_SECONDS = 60;

  /**
   * Constructs a CircuitBreaker.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The Drupal state service used to persist circuit state across requests.
   */
  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * Returns TRUE when the circuit is open and requests should be blocked.
   *
   * The circuit is considered open when the failure count has reached the
   * threshold AND the cooldown period has not yet elapsed.
   *
   * @return bool
   *   TRUE if the circuit is open, FALSE if requests may proceed.
   */
  public function isOpen(): bool {
    $circuit_state = $this->state->get(self::STATE_KEY);
    if ($circuit_state === NULL || $circuit_state['failures'] < self::FAILURE_THRESHOLD) {
      return FALSE;
    }
    return (time() - $circuit_state['opened_at']) < self::COOLDOWN_SECONDS;
  }

  /**
   * Records a successful request and resets the circuit to closed state.
   */
  public function recordSuccess(): void {
    $this->state->delete(self::STATE_KEY);
  }

  /**
   * Records a failed request and opens the circuit when the threshold is hit.
   *
   * The opened_at timestamp is stamped only when the failure count first
   * reaches FAILURE_THRESHOLD so the cooldown window is stable.
   */
  public function recordFailure(): void {
    $circuit_state = $this->state->get(self::STATE_KEY) ?? ['failures' => 0, 'opened_at' => 0];
    $circuit_state['failures']++;
    if ($circuit_state['failures'] === self::FAILURE_THRESHOLD) {
      $circuit_state['opened_at'] = time();
    }
    $this->state->set(self::STATE_KEY, $circuit_state);
  }

}
