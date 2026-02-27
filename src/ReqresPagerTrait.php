<?php

declare(strict_types=1);

namespace Drupal\reqres_users;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;

/**
 * Builds an AJAX-powered pager render array for the Reqres Users block.
 */
trait ReqresPagerTrait {

  /**
   * Builds a render array for the AJAX pager.
   *
   * @param int $current_page
   *   Zero-based current page index.
   * @param int $total_pages
   *   Total number of pages returned by the API.
   * @param string $wrapper_id
   *   The HTML id of the block wrapper that ReplaceCommand will target.
   *   Forwarded to the AJAX endpoint so it can reconstruct the same id.
   * @param array<string, mixed> $base_params
   *   Query parameters that remain constant across all pager links
   *   (wrapper_id, per_page, cache_ttl, label overrides).
   *
   * @return array<string, mixed>
   *   A render array containing the pager markup, or an empty array when only
   *   one page exists.
   */
  private function buildPager(int $current_page, int $total_pages, string $wrapper_id, array $base_params): array {
    if ($total_pages <= 1) {
      return [];
    }

    $items = '';

    if ($current_page > 0) {
      $url = Html::escape($this->buildPageUrl($current_page - 1, $wrapper_id, $base_params)->toString());
      $label = Html::escape((string) $this->t('‹ Previous'));
      $items .= '<li class="pager__item pager__item--previous">'
        . '<a href="' . $url . '" class="use-ajax">' . $label . '</a>'
        . '</li>';
    }

    for ($i = 0; $i < $total_pages; $i++) {
      if ($i === $current_page) {
        $items .= '<li class="pager__item is-active"><span>' . ($i + 1) . '</span></li>';
      }
      else {
        $url = Html::escape($this->buildPageUrl($i, $wrapper_id, $base_params)->toString());
        $items .= '<li class="pager__item">'
          . '<a href="' . $url . '" class="use-ajax">' . ($i + 1) . '</a>'
          . '</li>';
      }
    }

    if ($current_page < $total_pages - 1) {
      $url = Html::escape($this->buildPageUrl($current_page + 1, $wrapper_id, $base_params)->toString());
      $label = Html::escape((string) $this->t('Next ›'));
      $items .= '<li class="pager__item pager__item--next">'
        . '<a href="' . $url . '" class="use-ajax">' . $label . '</a>'
        . '</li>';
    }

    $aria_label = Html::escape((string) $this->t('Pagination'));
    $html = '<nav class="pager" aria-label="' . $aria_label . '">'
      . '<ul class="pager__items js-pager__items">' . $items . '</ul>'
      . '</nav>';

    return ['#markup' => Markup::create($html)];
  }

  /**
   * Builds a Url object for the AJAX pager endpoint at a given page.
   *
   * @param int $page
   *   Zero-based target page index.
   * @param string $wrapper_id
   *   The HTML wrapper id forwarded to the controller.
   * @param array<string, mixed> $base_params
   *   Constant query parameters to merge with the page index.
   */
  private function buildPageUrl(int $page, string $wrapper_id, array $base_params): Url {
    return Url::fromRoute(
      'reqres_users.ajax_pager',
      [],
      ['query' => array_merge($base_params, ['page' => $page, 'wrapper_id' => $wrapper_id])],
    );
  }

}
