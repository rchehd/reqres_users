<?php

declare(strict_types=1);

namespace Drupal\reqres_users\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reqres_users\Api\ReqresApiClient;
use Drupal\reqres_users\Api\ReqresApiClientInterface;
use Drupal\reqres_users\ReqresPagerTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block that lists users from the Reqres API with AJAX pagination.
 */
#[Block(
  id: 'reqres_users_block',
  admin_label: new TranslatableMarkup('Reqres Users'),
  category: new TranslatableMarkup('Custom'),
)]
class ReqresUsersBlock extends BlockBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;
  use ReqresPagerTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    string $pluginId,
    mixed $pluginDefinition,
    private readonly ReqresApiClientInterface $apiClient,
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $pluginId, $pluginDefinition): static {
    return new static(
      $configuration,
      $pluginId,
      $pluginDefinition,
      $container->get('reqres_users.api_client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'items_per_page' => 6,
      'cache_ttl' => 300,
      'instance_id' => '',
      'email_label' => $this->t('Email'),
      'forename_label' => $this->t('Forename'),
      'surname_label' => $this->t('Surname'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    $form['items_per_page'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of items per page'),
      '#default_value' => $config['items_per_page'],
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL (seconds)'),
      '#description' => $this->t('How long to cache the API response. Set to 0 to disable caching.'),
      '#default_value' => $config['cache_ttl'],
      '#min' => 0,
      '#required' => TRUE,
    ];

    $form['email_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email field label'),
      '#default_value' => $config['email_label'],
      '#required' => TRUE,
    ];

    $form['forename_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Forename field label'),
      '#default_value' => $config['forename_label'],
      '#required' => TRUE,
    ];

    $form['surname_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Surname field label'),
      '#default_value' => $config['surname_label'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->setConfigurationValue('items_per_page', (int) $form_state->getValue('items_per_page'));
    $this->setConfigurationValue('cache_ttl', (int) $form_state->getValue('cache_ttl'));
    $this->setConfigurationValue('email_label', $form_state->getValue('email_label'));
    $this->setConfigurationValue('forename_label', $form_state->getValue('forename_label'));
    $this->setConfigurationValue('surname_label', $form_state->getValue('surname_label'));

    if (empty($this->getConfiguration()['instance_id'])) {
      $this->setConfigurationValue('instance_id', bin2hex(random_bytes(8)));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $config = $this->getConfiguration();
    $per_page = (int) $config['items_per_page'];
    $cache_ttl = (int) $config['cache_ttl'];

    $wrapper_id = 'reqres-users-block-' . ($config['instance_id'] ?: 'unsaved');

    $result = $this->apiClient->getUsers(1, $per_page, $cache_ttl);

    $rows = array_map(
      static fn($user) => [$user->email, $user->firstName, $user->lastName],
      $result['users'],
    );

    $base_params = [
      'wrapper_id' => $wrapper_id,
      'per_page' => $per_page,
      'cache_ttl' => $cache_ttl,
      'email_label' => (string) $config['email_label'],
      'forename_label' => (string) $config['forename_label'],
      'surname_label' => (string) $config['surname_label'],
    ];

    return [
      '#theme' => 'reqres_user_list',
      '#users_table' => [
        '#theme' => 'table',
        '#header' => [
          $config['email_label'],
          $config['forename_label'],
          $config['surname_label'],
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No users found.'),
      ],
      '#users_pager' => $this->buildPager(0, $result['total_pages'], $wrapper_id, $base_params),
      '#prefix' => '<div id="' . $wrapper_id . '">',
      '#suffix' => '</div>',
      '#attached' => [
        'library' => ['core/drupal.ajax'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), [ReqresApiClient::CACHE_TAG]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return (int) $this->getConfiguration()['cache_ttl'];
  }

}
