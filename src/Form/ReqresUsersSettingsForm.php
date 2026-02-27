<?php

declare(strict_types=1);

namespace Drupal\reqres_users\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reqres_users\Api\ReqresApiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Module settings form for configuring the Reqres API connection.
 */
class ReqresUsersSettingsForm extends FormBase {

  public function __construct(private readonly StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('state'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reqres_users_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#description' => $this->t('The <code>x-api-key</code> header value sent with every Reqres API request. This value is stored in the database only and is <strong>never exported</strong> to <code>config/sync</code>, so it will not appear in version control.'),
      '#default_value' => (string) $this->state->get(ReqresApiClient::STATE_KEY, ''),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->state->set(ReqresApiClient::STATE_KEY, trim((string) $form_state->getValue('api_key')));
    $this->messenger()->addStatus($this->t('The API key has been saved.'));
  }

}
