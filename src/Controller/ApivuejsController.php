<?php

namespace Drupal\apivuejs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Stephane888\Debug\ExceptionExtractMessage;
use Drupal\Component\Serialization\Json;
use Symfony\Component\HttpFoundation\Request;
use Stephane888\DrupalUtility\HttpResponse;

/**
 * Returns responses for Api vuejs routes.
 */
class ApivuejsController extends ControllerBase {
  
  /**
   * Builds the response.
   */
  public function saveEntity(Request $Request, $entity_type_id): \Symfony\Component\HttpFoundation\JsonResponse {
    try {
      $defaultValues = Json::decode($Request->getContent());
      $entity = $this->entityTypeManager()->getStorage($entity_type_id)->create($defaultValues);
      $entity->save();
      return HttpResponse::response([
        'id' => $entity->id(),
        'json' => $entity->toArray()
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('buildercv')->critical($e->getMessage() . '<br>' . ExceptionExtractMessage::errorAllToString($e));
      return HttpResponse::response(ExceptionExtractMessage::errorAll($e), 400, $e->getMessage());
    }
  }
  
  /**
   * Permet de generer un tableau multi-dimentionnelle permettant de creer le
   * contenus de maniere recursive.
   * Cela permet de creer un enssemble de contenu sans pour autant surcharger
   * les ressources.
   */
  public function generateFormMatrice() {
    //
  }
  
}
