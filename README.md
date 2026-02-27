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

### Manual

Copy the module directory to `web/modules/custom/reqres_users/` and enable it via `drush en reqres_users` or the Extend UI.

## Configuration

### 1 — Set the API key

Navigate to **Administration → Configuration → Web services → Reqres Users**
(`/admin/config/services/reqres-users`) and enter your API key.

The key is persisted in Drupal State (database only). It is **never** exported to `config/sync` and will not appear in version control.

### 2 — Place the block

Go to **Administration → Structure → Block layout**, find the *Reqres Users* block under the Custom category, and place it in any region.

Each block instance exposes the following settings:

| Setting | Default | Description |
|---|---|---|
| Number of items per page | `6` | Rows fetched and displayed per page |
| Cache TTL (seconds) | `300` | How long to cache the API response. Set to `0` to disable. |
| Email field label | `Email` | Header label for the email column |
| Forename field label | `Forename` | Header label for the first-name column |
| Surname field label | `Surname` | Header label for the last-name column |

## Multiple block instances

Each block instance receives a unique internal ID generated automatically the first time its configuration form is saved. There is no manual setting required — two blocks placed on the same page will always target independent AJAX wrappers.

## Caching

Two layers of caching are in place:

1. **API response cache** — the raw API response is cached for `cache_ttl` seconds (tagged with `reqres_users`).
2. **Block render cache** — the rendered block is cached permanently and tagged with `reqres_users`.

On every API cache miss the module computes an MD5 hash of the raw response and compares it with the previously stored hash. If the data has changed, the `reqres_users` cache tag is invalidated, which immediately busts all rendered block variants across all pages. The hash entry itself is stored permanently (without the cache tag) so it survives tag invalidation and can detect the next change.

## Extension point — filtering users

Subscribe to `\Drupal\reqres_users\Event\FilterReqresUsersEvent` to add, remove, or reorder the users list before it is rendered:

```php
use Drupal\reqres_users\Event\FilterReqresUsersEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class MySubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [FilterReqresUsersEvent::EVENT_NAME => 'onFilter'];
  }

  public function onFilter(FilterReqresUsersEvent $event): void {
    $users = array_filter(
      $event->getUsers(),
      static fn($u) => str_ends_with($u->email, '@reqres.in'),
    );
    $event->setUsers(array_values($users));
  }

}
```

Register the subscriber in your module's `*.services.yml`:

```yaml
services:
  my_module.reqres_filter:
    class: Drupal\my_module\EventSubscriber\MySubscriber
    tags:
      - { name: event_subscriber }
```

> **Note:** filtering is applied after the API request, so `total` and `total_pages` reflect the unfiltered API counts. The number of rows on a given page may fall below *items per page* when subscribers remove entries.

## Developer notes

### AJAX endpoint

Pager navigation calls `GET /reqres-users/ajax` with the following query parameters (all generated automatically by the block — no manual construction needed):

| Parameter | Description |
|---|---|
| `page` | Zero-based page index |
| `per_page` | Items per page |
| `cache_ttl` | Cache TTL in seconds |
| `wrapper_id` | HTML id of the block wrapper to replace |
| `email_label` | Column label |
| `forename_label` | Column label |
| `surname_label` | Column label |

### Running unit tests

The test suite is self-contained and does not require a running Drupal site.

```bash
cd web/modules/custom/reqres_users
composer install
vendor/bin/phpunit
```

Tests cover: cache hits, DTO mapping, pagination parameters, TTL-based caching, hash-based cache invalidation, error handling, event dispatching, and API key header forwarding.

### Project structure

```
reqres_users/
├── js/                              # Unused — AJAX handled by core/drupal.ajax
├── src/
│   ├── Api/
│   │   ├── ReqresApiClient.php      # HTTP client, caching, hash invalidation
│   │   └── ReqresApiClientInterface.php
│   ├── Controller/
│   │   └── ReqresUsersAjaxController.php  # AJAX pager endpoint
│   ├── Dto/
│   │   └── UserDto.php              # Immutable value object for a single user
│   ├── Event/
│   │   └── FilterReqresUsersEvent.php     # Dispatched before rendering
│   ├── Form/
│   │   └── ReqresUsersSettingsForm.php    # API key settings form
│   ├── Plugin/Block/
│   │   └── ReqresUsersBlock.php     # Block plugin
│   └── ReqresPagerTrait.php         # Shared AJAX pager builder (block + controller)
├── templates/
│   └── reqres-user-list.html.twig
├── tests/Unit/
│   └── ReqresApiClientTest.php
├── composer.json
├── phpunit.xml.dist
├── reqres_users.info.yml
├── reqres_users.libraries.yml
├── reqres_users.links.menu.yml
├── reqres_users.module
├── reqres_users.routing.yml
└── reqres_users.services.yml
```
