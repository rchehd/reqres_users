<?php

declare(strict_types=1);

namespace Drupal\reqres_users\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\reqres_users\Contract\UserProviderInterface;
use Drupal\reqres_users\Exception\ApiException;
use Drupal\reqres_users\ReqresPagerTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns an AJAX response replacing the block wrapper for a given pager page.
 */
class ReqresUsersAjaxController extends ControllerBase {

  use ReqresPagerTrait;

  /**
   * Constructor.
   *
   * @param \Drupal\reqres_users\Contract\UserProviderInterface $userProvider
   *   The user provider for fetching users.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer for rendering the AJAX response.
   */
  public function __construct(
    private readonly UserProviderInterface $userProvider,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('reqres_users.api_client'),
      $container->get('renderer'),
    );
  }

  /**
   * Returns an AjaxResponse replacing the block wrapper with a new page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AJAX response containing a ReplaceCommand for the block wrapper.
   */
  public function page(Request $request): AjaxResponse {
    $page = max(0, (int) $request->query->get('page', 0));
    $per_page = max(1, (int) $request->query->get('per_page', 6));
    $cache_ttl = max(0, (int) $request->query->get('cache_ttl', 300));
    $email_label = Xss::filter((string) $request->query->get('email_label', 'Email'));
    $forename_label = Xss::filter((string) $request->query->get('forename_label', 'Forename'));
    $surname_label = Xss::filter((string) $request->query->get('surname_label', 'Surname'));

    $wrapper_id = Html::cleanCssIdentifier(
      Xss::filter((string) $request->query->get('wrapper_id', '')),
    );

    try {
      // Reqres API uses 1-based page index.
      $result = $this->userProvider->getUsers($page + 1, $per_page, $cache_ttl);
    }
    catch (ApiException $e) {
      $html = '<div id="' . Html::escape($wrapper_id) . '">'
        . $this->t('User data is temporarily unavailable.')
        . '</div>';
      $response = new AjaxResponse();
      $response->addCommand(new ReplaceCommand('#' . $wrapper_id, $html));
      return $response;
    }

    $rows = array_map(
      static fn($user) => [$user->email, $user->firstName, $user->lastName],
      $result->getUsers(),
    );

    $base_params = [
      'wrapper_id' => $wrapper_id,
      'per_page' => $per_page,
      'cache_ttl' => $cache_ttl,
      'email_label' => $email_label,
      'forename_label' => $forename_label,
      'surname_label' => $surname_label,
    ];

    $build = [
      '#theme' => 'reqres_user_list',
      '#users_table' => [
        '#theme' => 'table',
        '#header' => [$email_label, $forename_label, $surname_label],
        '#rows' => $rows,
        '#empty' => $this->t('No users found.'),
      ],
      '#users_pager' => $this->buildPager($page, $result->getTotalPages(), $wrapper_id, $base_params),
      '#prefix' => '<div id="' . Html::escape($wrapper_id) . '">',
      '#suffix' => '</div>',
      '#attached' => [
        'library' => ['core/drupal.ajax'],
      ],
    ];

    $html = (string) $this->renderer->renderRoot($build);

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#' . $wrapper_id, $html));

    return $response;
  }

}
