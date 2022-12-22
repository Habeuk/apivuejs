<?php

namespace Drupal\apivuejs\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Returns responses for Api vuejs routes.
 */
class ApivuejsController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function build() {

    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('It works!'),
    ];

    return $build;
  }

}
