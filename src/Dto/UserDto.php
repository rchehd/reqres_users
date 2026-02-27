<?php

declare(strict_types=1);

namespace Drupal\reqres_users\Dto;

/**
 * Represents a data transfer object for a user.
 */
class UserDto {

  /**
   * Initializes a new instance of the class with the provided data.
   *
   * @param int $id
   *   The unique identifier for the instance.
   * @param string $email
   *   The email address associated with the instance.
   * @param string $firstName
   *   The first name of the individual.
   * @param string $lastName
   *   The last name of the individual.
   */
  public function __construct(
    public int $id,
    public string $email,
    public string $firstName,
    public string $lastName,
  ) {}

  /**
   * Constructs a UserDto from a raw API response item.
   *
   * @param array{id: int|string, email: string, first_name: string, last_name: string} $data
   *   A single item from the Reqres API 'data' array.
   */
  public static function fromApiData(array $data): self {
    return new self(
      id: (int) $data['id'],
      email: (string) $data['email'],
      firstName: (string) $data['first_name'],
      lastName: (string) $data['last_name'],
    );
  }

}
