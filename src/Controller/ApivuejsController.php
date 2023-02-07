<?php

namespace Drupal\apivuejs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Stephane888\Debug\ExceptionExtractMessage;
use Drupal\Component\Serialization\Json;
use Symfony\Component\HttpFoundation\Request;
use Stephane888\DrupalUtility\HttpResponse;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\layout_builder\Section;

/**
 * Returns responses for Api vuejs routes.
 */
class ApivuejsController extends ControllerBase {
  /**
   *
   * @var \Drupal\Core\Entity\EntityAccessControlHandler
   */
  protected $EntityAccessControlHandler;
  
  /**
   * Contient la liste des champs.
   *
   * @var array
   */
  protected $Allfields = [];
  
  /**
   * Cree les nouveaux entitées et dupliqué les entites existant.
   *
   * @param Request $Request
   * @param string $entity_type_id
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function saveEntity(Request $Request, $entity_type_id): \Symfony\Component\HttpFoundation\JsonResponse {
    $EntityStorage = $this->entityTypeManager()->getStorage($entity_type_id);
    $values = Json::decode($Request->getContent());
    $this->getLayoutBuilderField($values);
    
    //
    if ($EntityStorage && !empty($values)) {
      try {
        /**
         * --
         *
         * @var \Drupal\Core\Entity\EntityInterface $entity
         */
        $entity = $EntityStorage->create($values);
        if ($entity->id()) {
          /**
           *
           * @var ContentEntityInterface $OldEntity
           */
          $OldEntity = $EntityStorage->load($entity->id());
          // on doit charger les données en fonction de la langue encours.
          $lang_code = \Drupal::languageManager()->getCurrentLanguage()->getId();
          if ($OldEntity->hasTranslation($lang_code)) {
            $OldEntity = $OldEntity->getTranslation($lang_code);
          }
          
          if (!empty($OldEntity)) {
            // on doit controller l'access avant la MAJ pour les champs
            // de type contentEntity.
            if ($EntityStorage->getEntityType()->getBaseTable())
              foreach ($values as $k => $value) {
                if ($this->checkAccessEditField($OldEntity, $k))
                  $OldEntity->set($k, $value);
              }
            else {
              // pour les entites de configuration on doit aussi voir si le
              // control d'access fonctionne ou comment mettre cela en place.
              foreach ($values as $k => $value) {
                $OldEntity->set($k, $value);
              }
            }
            // save entity after control.
            $OldEntity->save();
            return HttpResponse::response([
              'id' => $OldEntity->id(),
              'json' => $OldEntity->toArray()
            ]);
          }
          // cest un nouveau contenu, les ids pour les entities de configuration
          // sont generalment generer en amont.
          else {
            $entity->save();
            return HttpResponse::response([
              'id' => $entity->id(),
              'json' => $entity->toArray()
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
  
  protected function getLayoutBuilderField(array &$entity) {
    if (!empty($entity['layout_builder__layout'])) {
      foreach ($entity['layout_builder__layout'] as $i => $sections) {
        foreach ($sections as $s => $section) {
          /**
           *
           * @var \Drupal\layout_builder\Section $section
           */
          $entity['layout_builder__layout'][$i][$s] = Section::fromArray($section);
        }
      }
    }
  }
  
  /**
   *
   * @param ContentEntityInterface $entity
   * @param string $fieldname
   */
  protected function checkAccessEditField(ContentEntityInterface $entity, $fieldname) {
    $this->LoadAccessControlFields($entity);
    if (!empty($this->Allfields[$fieldname])) {
      return $this->EntityAccessControlHandler->fieldAccess('edit', $this->Allfields[$fieldname]);
    }
    return false;
  }
  
  protected function LoadAccessControlFields(ContentEntityInterface $entity) {
    // Dans le cadre de la MAJ on doit verfier l'access au champs.
    if (!$this->EntityAccessControlHandler)
      $this->EntityAccessControlHandler = new EntityAccessControlHandler($entity->getEntityType());
    /**
     *
     * @var \Drupal\Core\Entity\EntityFieldManager $entityManager
     */
    if (empty($this->Allfields)) {
      $entityManager = \Drupal::service('entity_field.manager');
      $this->Allfields = $entityManager->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle());
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
