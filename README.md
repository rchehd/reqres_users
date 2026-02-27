# Reqres Users

Drupal 10/11 module that fetches users from the [Reqres](https://reqres.in) dummy REST API and displays them in a paginated, AJAX-powered block.

## Features

- Block showing user email, forename, and surname in a table
- AJAX pagination — page changes replace only the block, no full reload
- Configurable items per page, cache TTL, and column labels
- API response cached with hash-based invalidation (cache is busted only when data changes)
- API key stored in Drupal State — never written to `config/sync` or version control
- Filter extension point via a Symfony event
- Multiple block instances on the same page are fully supported
- PHPUnit unit tests runnable outside a full Drupal installation

## Requirements

- PHP 8.1+
- Drupal 10 or 11
- `drupal:block` module (core)
- A Reqres API key (obtain from [reqres.in](https://reqres.in))

## Installation

### Via Composer (recommended)

```bash
composer require drupal/reqres_users
drush en reqres_users
```
