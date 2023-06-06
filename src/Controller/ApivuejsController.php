<?php

namespace Drupal\apivuejs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Serialization\Json;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\ContentEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\layout_builder\Section;
use Stephane888\Debug\ExceptionDebug;
use Stephane888\DrupalUtility\HttpResponse;
use Stephane888\Debug\ExceptionExtractMessage;
use Drupal\apivuejs\Services\DuplicateEntityReference;
use Drupal\apivuejs\Services\GenerateForm;

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
   *
   * @var \Drupal\apivuejs\Services\GenerateForm
   */
  protected $GenerateForm;
  
  /**
   * Contient la liste des champs.
   *
   * @var array
   */
  protected $Allfields = [];
  
  /**
   *
   * @var DuplicateEntityReference
   */
  protected $DuplicateEntityReference;
  
  public function __construct(DuplicateEntityReference $DuplicateEntityReference, GenerateForm $GenerateForm) {
    $this->DuplicateEntityReference = $DuplicateEntityReference;
    $this->GenerateForm = $GenerateForm;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('apivuejs.duplicate_reference'), $container->get('apivuejs.getform'));
  }
  
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
          
          if (!empty($OldEntity)) {
            // on doit controller l'access avant la MAJ pour les champs
            // de type contentEntity.
            if ($EntityStorage->getEntityType()->getBaseTable()) {
              // On doit charger les données en fonction de la langue encours.
              $lang_code = \Drupal::languageManager()->getCurrentLanguage()->getId();
              if ($OldEntity->hasTranslation($lang_code)) {
                $OldEntity = $OldEntity->getTranslation($lang_code);
              }
              //
              foreach ($values as $k => $value) {
                if ($this->checkAccessEditField($OldEntity, $k))
                  $OldEntity->set($k, $value);
              }
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
          // cest un nouveau contenu, ( les ids pour les entities de
          // configuration sont generalment generer en amont ).
          else {
            $entity->save();
            return HttpResponse::response([
              'id' => $entity->id(),
              'json' => $entity->toArray()
            ]);
          }
        }
        else {
          // pour les nouveaux contenus, s'ils ont été generé à partir d'une
          // autre langue, il faut mettre à jour le valeur default_langcode
          if (isset($values['default_langcode'][0]['value']) && $values['default_langcode'][0]['value'] == 0) {
            $values['default_langcode'][0]['value'] = 1;
            $entity = $EntityStorage->create($values);
          }
          
          /**
           * La sauvegarde brute n'est pas toujours adapté, car les données
           * peuvent etre dans un format incompable.
           * Examaple le champs date: peut etre etre integrer avec un varchar,
           * integer, un date ...
           * Mais on a pas de solution pour le moment.( donc au front bien
           * formater les données.
           */
          // $entity = $EntityStorage->create($values);
          // on doit controller l'access avant la MAJ pour les champs
          // de type contentEntity.
          // if ($EntityStorage->getEntityType()->getBaseTable()) {
          // // on verifie l'acces et on MAJ les données avec set.
          // foreach ($values as $k => $value) {
          // if ($this->checkAccessEditField($entity, $k))
          // $entity->set($k, $value);
          // }
          // }
          // else {
          // // pour les entites de configuration on doit aussi voir si le
          // // control d'access fonctionne ou comment mettre cela en place.
          // foreach ($values as $k => $value) {
          // $entity->set($k, $value);
          // }
          // }
          
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
   * Drupal pour le moment a opter de ne pas exposer les données de layouts
   * builder, car ce dernier utilise le format json et un ya quelques probleme
   * de logique ou conception.
   * Pour remedier à cela, on opte de fournir le nessaire pour son import en
   * attendant la reponse de drupal.
   *
   * @see https://www.drupal.org/project/drupal/issues/2942975
   *
   * @param array $entity
   */
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
  
  public function EntittiDelete(Request $Request) {
    try {
      $param = Json::decode($Request->getContent());
      if (empty($param['id']) || empty($param['entity_type_id']) || !isset($param['delete_subentities']))
        throw ExceptionDebug::exception(" Paramettre manquant ");
      /**
       *
       * @var \Drupal\Core\Entity\EntityInterface $entity
       */
      $entity = $this->entityTypeManager()->getStorage($param['entity_type_id'])->load($param['id']);
      if ($entity) {
        /**
         * on verifie si c'est une entite de configuration ou pas.
         */
        if ($entity->getEntityType()->getBaseTable()) {
          $entity->delete();
        }
        else {
          $query = $this->entityTypeManager()->getStorage($entity->getEntityType()->getBundleOf())->getQuery();
          $query->condition('type', $param['id']);
          $nbre = $query->count()->execute();
          if ($nbre) {
            if (!$param['delete_subentities']) {
              // throw new \Exception("L'entité contient de elements");
              throw ExceptionDebug::exception("L'entité contient de elements, veillez supprimer ces derniers");
              // throw new \LogicException("L'entité contient de elements,
              // veillez supprimer ces derniers");
            }
            else {
              $storage_handler = $this->entityTypeManager()->getStorage($entity->getEntityType()->getBundleOf());
              $entities = $storage_handler->loadByProperties([
                "type" => $param['id']
              ]);
              $storage_handler->delete($entities);
              //
              $entity->delete();
            }
          }
          else {
            $entity->delete();
          }
        }
        return HttpResponse::response([
          'has deleted' => $param['id'],
          'entity_type_id' => $param['entity_type_id']
        ]);
      }
      throw new ExceptionDebug(" L'entité n'existe plus ");
    }
    catch (ExceptionDebug $e) {
      return HttpResponse::response(ExceptionExtractMessage::errorAll($e), $e->getErrorCode(), $e->getMessage());
    }
    catch (\Exception $e) {
      return HttpResponse::response(ExceptionExtractMessage::errorAll($e), 432, $e->getMessage());
    }
    catch (\Error $e) {
      return HttpResponse::response(ExceptionExtractMessage::errorAll($e), 432, $e->getMessage());
    }
  }
  
  /**
   * Generer une structure qui permet d'editer ou de dupliquer une entite via
   * vuejs.
   *
   * @param Request $Request
   * @throws ExceptionDebug
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  function getFormByEntityId(Request $Request) {
    try {
      $param = Json::decode($Request->getContent());
      if (empty($param['id']) || empty($param['entity_type_id']))
        throw new ExceptionDebug(" Paramettre manquant ");
      //
      $entity = $this->entityTypeManager()->getStorage($param['entity_type_id'])->load($param['id']);
      $duplicate = false;
      if ($entity) {
        if (!empty($param['duplicate'])) {
          $entity = $entity->createDuplicate();
          $duplicate = true;
        }
        $res = [];
        $res[] = $this->generateFormMatrice($param['entity_type_id'], $entity, $entity->bundle(), $duplicate);
        return HttpResponse::response($res);
      }
      throw new ExceptionDebug(" L'entité n'existe plus ");
    }
    catch (ExceptionDebug $e) {
      return HttpResponse::response(ExceptionExtractMessage::errorAll($e), $e->getErrorCode(), $e->getMessage());
    }
    catch (\Exception $e) {
      return HttpResponse::response(ExceptionExtractMessage::errorAll($e), 431, $e->getMessage());
    }
    catch (\Error $e) {
      return HttpResponse::response(ExceptionExtractMessage::errorAll($e), 431, $e->getMessage());
    }
  }
  
  /**
   * Generer une structure qui permet de creer une nouvelle entity via vuejs.
   * Cette entité peut ausi etre creer à partir du type d'entité.
   * NB: renvoit les données uniquement pour les
   * Drupal\Core\Entity\ContentEntityBase, pour les
   * Drupal\Core\Config\Entity\ConfigEntityBundleBase voir
   */
  public function getContentEntityForm($entity_type_id, $bundle = null, $view_mode = 'default') {
    try {
      /**
       *
       * @var \Drupal\Core\Config\Entity\ConfigEntityStorage $EntityStorage
       */
      $EntityStorage = $this->entityTypeManager()->getStorage($entity_type_id);
      // On determine si c'est un entity de configuration ou une entité de
      // contenu.
      // pour le moment, on peut differencier l'un de l'autre via la table de
      // base, seul les entités de contenus ont une table de base.
      /**
       *
       * @var \Drupal\Core\Config\Entity\ConfigEntityType $entityT
       */
      $entityT = $EntityStorage->getEntityType();
      if (!$entityT->getBaseTable()) {
        $entity_type_id = $entityT->getBundleOf();
        $EntityStorage = $this->entityTypeManager()->getStorage($entity_type_id);
      }
      if (empty($EntityStorage))
        throw new \Exception("Le type d'entité n'exsite pas : " . $entity_type_id);
      
      if ($bundle && $bundle != $entity_type_id)
        $entity = $EntityStorage->create([
          'type' => $bundle
        ]);
      else {
        $bundle = $entity_type_id;
        $entity = $EntityStorage->create();
      }
      $res = [];
      $res[] = $this->generateFormMatrice($entity_type_id, $entity, $bundle);
      return HttpResponse::response($res);
    }
    catch (\Exception $e) {
      return HttpResponse::response(ExceptionExtractMessage::errorAll($e), 400, $e->getMessage());
    }
    catch (\Error $e) {
      return HttpResponse::response(ExceptionExtractMessage::errorAll($e), 400, $e->getMessage());
    }
  }
  
  /**
   * * Permet de generer un tableau multi-dimentionnelle permettant de creer le
   * contenus de maniere recursive.
   * Cela permet de creer un enssemble de contenu sans pour autant surcharger
   * les ressources.
   *
   * @param string $entity_type_id
   * @param string $bundle
   * @param string $view_mode
   * @param \Drupal\Core\Entity\ContentEntityBase $entity
   */
  protected function generateFormMatrice($entity_type_id, \Drupal\Core\Entity\ContentEntityBase $entity, $bundle, $duplicate = false, $add_form = true, $view_mode = 'default') {
    $form = $this->GenerateForm->getForm($entity_type_id, $bundle, $view_mode, $entity);
    // Ajout de la configuration des champs layout_builder__layout. ( il faudra
    // completer l'issue ).
    $this->DuplicateEntityReference->toArrayLayoutBuilderField($form['entity']);
    $entities = [];
    $this->DuplicateEntityReference->duplicateExistantReference($entity, $entities, $duplicate, $add_form);
    $form['entities'] = $entities;
    return $form;
  }
  
}
