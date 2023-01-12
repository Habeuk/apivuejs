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
  // public function saveEntity(Request $Request, $entity_type_id):
  // \Symfony\Component\HttpFoundation\JsonResponse {
  // try {
  // $defaultValues = Json::decode($Request->getContent());
  // $entity =
  // $this->entityTypeManager()->getStorage($entity_type_id)->create($defaultValues);
  // $entity->save();
  // return HttpResponse::response([
  // 'id' => $entity->id(),
  // 'json' => $entity->toArray()
  // ]);
  // }
  // catch (\Exception $e) {
  // $this->getLogger('buildercv')->critical($e->getMessage() . '<br>' .
  // ExceptionExtractMessage::errorAllToString($e));
  // return HttpResponse::response(ExceptionExtractMessage::errorAll($e), 400,
  // $e->getMessage());
  // }
  // }
  
  /**
   * Cree les nouveaux entitées et duplique les entites existant.
   *
   * @param Request $Request
   * @param string $entity_type_id
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function saveEntity(Request $Request, $entity_type_id): \Symfony\Component\HttpFoundation\JsonResponse {
    $entity_type = $this->entityTypeManager()->getStorage($entity_type_id);
    $values = Json::decode($Request->getContent());
    //
    if ($entity_type && !empty($values)) {
      try {
        /**
         */
        $entity = $entity_type->create($values);
        if ($entity->id()) {
          $OldEntity = $this->entityTypeManager()->getStorage($entity_type_id)->load($entity->id());
          if (!empty($OldEntity)) {
            foreach ($values as $k => $value) {
              $OldEntity->set($k, $value);
            }
            $OldEntity->save();
            return HttpResponse::response([
              'id' => $OldEntity->id(),
              'json' => $OldEntity->toArray()
            ]);
          }
        }
        else {
          $entity->save();
          return HttpResponse::response([
            'id' => $entity->id(),
            'json' => $entity->toArray()
          ]);
        }
        throw new \Exception("Erreur d'execution");
      }
      catch (\Exception $e) {
        $user = \Drupal::currentUser();
        $errors = '<br> error create : ' . $entity_type_id;
        $errors .= '<br> current user id : ' . $user->id();
        $errors .= ExceptionExtractMessage::errorAllToString($e);
        $this->getLogger('buildercv')->critical($e->getMessage() . '<br>' . $errors);
        return HttpResponse::response(ExceptionExtractMessage::errorAll($e), 400, $e->getMessage());
      }
    }
    else {
      $this->getLogger('buildercv')->critical(" impossible de creer l'entité : " . $entity_type_id);
      return HttpResponse::response([], 400, "erreur inconnu");
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
