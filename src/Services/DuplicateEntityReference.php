<?php

namespace Drupal\apivuejs\Services;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Drupal\block_content\Entity\BlockContent;
use Drupal\commerce_product\Entity\Product;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\blockscontent\Entity\BlocksContents;
use Drupal\Component\Serialization\Yaml;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityInterface;

class DuplicateEntityReference extends ControllerBase {
  protected static $field_domain_access = null;
  protected static $field_domain_all_affiliates = null;
  /**
   *
   * @var \Drupal\apivuejs\Services\GenerateForm
   */
  protected $GenerateForm;
  
  /**
   * The config storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $configStorage;
  
  function __construct(GenerateForm $GenerateForm, StorageInterface $config_storage) {
    $this->GenerateForm = $GenerateForm;
    $this->configStorage = $config_storage;
    $this->getFieldsDomain();
  }
  
  /**
   * Contient les données en JSON
   *
   * @var array
   */
  protected $datasJson = [];
  
  /**
   * Entite valide pour la suppresion.
   * Afin d'eviter de supprimer certaines données utile.
   *
   * @var array
   */
  protected $validEntity = [
    'paragraph',
    'node',
    'block_content',
    'commerce_product'
    // 'webform'
  ];
  
  /**
   * Les entitées ou types qui seront ignorées.
   *
   * @var array
   */
  protected $ignorEntity = [
    'user',
    'domain',
    'paragraphs_type',
    'site_internet_entity_type',
    'file',
    'commerce_product_type',
    'node_type',
    'blocks_contents_type'
  ];
  
  /**
   * Entites valide pour la duplications.
   *
   * @var array
   */
  protected $duplicable_entities_types = [
    "paragraph",
    "blocks_contents",
    "block_content",
    "node",
    "webform",
    "commerce_product",
    "commerce_promotion",
    "commerce_promotion_coupon"
  ];
  protected $lang_code;
  
  /**
   * Recuperer le nom du champs permettant d'associer un contenu à un domaine,
   * si le module domain_access est installé.
   */
  private function getFieldsDomain() {
    if (\Drupal::moduleHandler()->moduleExists('domain_access')) {
      self::$field_domain_access = \Drupal\domain_access\DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD;
    }
  }
  
  /**
   * Permet de supprimier les references dans l'entité.
   *
   * @param ContentEntityBase $entity
   */
  public function deleteExistantReference(ContentEntityBase &$entity) {
    $values = $entity->toArray();
    foreach ($values as $k => $vals) {
      if (!empty($vals[0]['target_id'])) {
        $setings = $entity->get($k)->getSettings();
        if (!empty($setings['target_type']) && in_array($setings['target_type'], $this->validEntity)) {
          $entityType = $this->entityTypeManager()->getStorage($setings['target_type']);
          foreach ($vals as $value) {
            $entityValue = $entityType->load($value['target_id']);
            // On verifie si ce dernier contient des references, si c'est le
            // cas,
            // on les supprime.
            if ($entityValue) {
              $this->deleteExistantReference($entityValue);
              $entityValue->delete();
            }
          }
        }
      }
    }
  }
  
  // **************************************IMPORTANT
  // Les deux méthodes qui suivent sont des copies de méthodes qui existent déjà
  /**
   * Get the translated configuration set.
   *
   * This configuration set is complete with all keys that the original language
   * has to offer. Every key that has a translation will have the translated
   * value in its place. Merging is done using array_replace_recursive().
   *
   * @param string $configName
   *        The name of the configuration file.
   * @param string $langCode
   *        The language id. Leave empty for current Drupal language.
   *        
   * @return array Returns the combined translated configuration as an object.
   * @deprecated all the language are generated so with another logic and this
   *             shouldn't be used anymore
   */
  public function getTranslatedConfig($configName, $langCode = NULL) {
    if (empty($langCode)) {
      $langCode = $this->languageManager()->getCurrentLanguage()->getId();
    }
    $originalConfig = $this->configStorage->read($configName);
    if (!$originalConfig) {
      return false;
    }
    $translatedConfig = $this->languageManager()->getLanguageConfigOverride($langCode, $configName)->get();
    $configs = array_replace_recursive($originalConfig, $translatedConfig);
    if (strpos($configName, 'webform') === 0 && isset($translatedConfig["elements"]) && isset($originalConfig["elements"])) {
      $elements = Yaml::decode($originalConfig["elements"]);
      $translatedElements = Yaml::decode($translatedConfig["elements"]);
      foreach ($translatedElements as $key => $element) {
        $this->deepFoundAndMerge($key, $element, $elements);
      }
      $configs["elements"] = Yaml::encode($elements);
      $configs["langcode"] = $langCode;
    }
    return $configs;
  }
  
  /**
   * replace recursively the value of the key once in the array
   * it will replace the first element it will find deeply
   */
  public function deepFoundAndMerge(string|int $key, mixed &$value, array &$array) {
    if (isset($array[$key])) {
      $array[$key] = array_replace_recursive($array[$key], $value);
      return true;
    }
    $found = false;
    foreach ($array as &$subArray) {
      if (gettype($subArray) == "array") {
        if ($this->deepFoundAndMerge($key, $value, $subArray)) {
          $found = true;
          break;
        }
      }
    }
    return $found;
  }
  
  /**
   * Copie the translated $lancode version of sourceName config
   * in the $langcode version of targetName config
   *
   * @param string $targetName
   * @param string $sourceName
   * @param string $langcode
   */
  public function setTranslatedConfig($targetName, $sourceName, $langcode) {
    $sourceConfigs = $this->languageManager()->getLanguageConfigOverride($langcode, $sourceName)->get();
    /**
     *
     * @var \Drupal\language\Config\LanguageConfigOverride $targetConfig
     */
    $targetConfig = $this->languageManager()->getLanguageConfigOverride($langcode, $targetName);
    $targetConfig->setData($sourceConfigs);
    $targetConfig->save();
  }
  
  /**
   * Permet de dupliquer une entité si $duplicate=true et uniquement les sous
   * entitées dans le cas contraire.
   * Cette logique est adapté pour un environnement restant sur Drupal.
   *
   * @param ContentEntityBase $entity
   * @param boolean $is_sub
   *        true return l'id de lentité et false retourne l'entité
   * @param array $fieldsList
   *        // les champs à dupliquer uniquement pour l'entite de base.
   * @param array $setFields
   *        // les champs qui doivent etre mise à jour.
   *        
   * @return \Drupal\Core\Entity\ContentEntityBase
   */
  public function duplicateEntity(EntityInterface $entity, bool $is_sub = false, array $fieldsList = [], array $setFields = [], $duplicate = true) {
    $EntityTypeId = $entity->getEntityTypeId();
    if ($duplicate && $EntityTypeId == 'commerce_product') {
      $newEntity = $this->duplicateProductEntity($entity);
    }
    elseif ($duplicate) {
      $newEntity = $entity->createDuplicate();
    }
    else {
      $newEntity = $entity;
    }
    
    if ($EntityTypeId == 'webform') {
      if (\Drupal::moduleHandler()->moduleExists('webform_domain_access') && !empty($setFields[self::$field_domain_access])) {
        $newEntity->setThirdPartySetting('webform_domain_access', self::$field_domain_access, $setFields[self::$field_domain_access]);
      }
      $newEntity->set("id", \strtolower(substr($entity->id(), 0, 10) . date('mdi') . rand(0, 9999)));
      $newEntity->save();
      $sourceConfigName = $this->entityTypeManager()->getDefinition("webform")->getConfigPrefix() . '.' . $entity->id();
      $configName = $this->entityTypeManager()->getDefinition("webform")->getConfigPrefix() . '.' . $newEntity->id();
      foreach ($this->languageManager()->getLanguages() as $langcode => $language) {
        if ($newEntity->language()->getId() !== $langcode) {
          $this->setTranslatedConfig($configName, $sourceConfigName, $langcode);
        }
      }
    }
    elseif ($newEntity instanceof ContentEntityBase) {
      $this->DefaultUpdateEntity($newEntity);
      if ($setFields)
        $this->setValues($newEntity, $setFields);
      $arrayValue = $fieldsList ? $fieldsList : $newEntity->toArray();
      foreach ($arrayValue as $field_name => $value) {
        $settings = $entity->get($field_name)->getSettings();
        // Duplicate sub entities.
        if (!empty($settings['target_type']) && in_array($settings['target_type'], $this->duplicable_entities_types)) {
          $valueList = [];
          foreach ($value as $entity_id) {
            $target_id = $entity_id['target_id'];
            $sub_entity = $this->entityTypeManager()->getStorage($settings['target_type'])->load($target_id);
            if (!empty($sub_entity)) {
              // On doit toujours dupliquer les elements enfants.
              $NewReference = $this->duplicateEntity($sub_entity, false, [], $setFields, true);
              $nVal["target_id"] = $NewReference->id();
              if (!empty($entity_id['target_revision_id'])) {
                $nVal["target_revision_id"] = $NewReference->getRevisionId();
              }
              $valueList[] = $nVal;
            }
          }
          $newEntity->set($field_name, $valueList);
          if (!empty($valueList)) {
            // Mise à jour de valeur dans les différentes traductions
            $entityTranslations = $entity->getTranslationLanguages();
            
            foreach ($entityTranslations as $langCode => $translation) {
              
              if ($langCode != $entity->get("langcode")->getValue()[0]["value"]) {
                $newEntity_translation = $newEntity->getTranslation($langCode);
                $newEntity_translation->set($field_name, $valueList);
              }
            }
          }
        }
      }
      // get translation
      $defaultLangcode = $this->languageManager()->getCurrentLanguage()->getId();
      $entityLanguages = $entity->getTranslationLanguages();
      unset($entityLanguages[$defaultLangcode]);
      foreach ($entityLanguages as $langcode => $language) {
        if ($entity->hasTranslation($langcode)) {
          $translationSource = $entity->getTranslation($langcode)->toArray();
          if (!$newEntity->hasTranslation($langcode)) {
            $newEntity->addTranslation($langcode, $translationSource);
          }
        }
      }
      $newEntity->save();
    }
    return $is_sub ? $newEntity->id() : $newEntity;
  }
  
  /**
   * Permet de mettre à jour un contenu dupliqué.
   * Context :
   * Nous avons duplique un contenu node : 150 à partir du node 12.
   * Nous avons MAJ le node 12, nous souhaitons repercuté ces changements sur le
   * node 150.
   * Mise en place :
   * Dans un premier temps on supprime les anciens sous contenus, ensuite on
   * ajoute les ajoutes les nouveaux contenus via la methode duplicateEntity
   * avec $duplicate=false. Voir une implementation dans
   * \Drupal\content_duplicator\Services\Manager::updateClone.
   *
   *
   *
   * @param ContentEntityBase $entity
   * @param boolean $is_sub
   *        true return l'id de lentité et false retourne l'entité
   * @param array $fieldsList
   *        // les champs à supprimer uniquement pour l'entite de base.
   *        
   * @return void
   */
  public function deleteSubEntity(EntityInterface &$entity, array $fieldsList = [], $level = 1) {
    $EntityTypeId = $entity->getEntityTypeId();
    if ($EntityTypeId == 'webform') {
      $entity->delete();
    }
    elseif ($entity instanceof ContentEntityBase) {
      $arrayValue = $fieldsList ? $fieldsList : $entity->toArray();
      foreach ($arrayValue as $field_name => $value) {
        $settings = $entity->get($field_name)->getSettings();
        // delete sub entities.
        if (!empty($settings['target_type']) && in_array($settings['target_type'], $this->duplicable_entities_types)) {
          foreach ($value as $entity_id) {
            $sub_entity = $this->entityTypeManager()->getStorage($settings['target_type'])->load($entity_id['target_id']);
            if (!empty($sub_entity)) {
              $sub_level = $level + 1;
              $this->deleteSubEntity($sub_entity, $fieldsList, $sub_level);
            }
          }
          $entity->set($field_name, []);
        }
      }
      // On sauvegarde uniquement pour le niveau 1.
      if ($level === 1) {
        $entity->save();
      }
      elseif ($level > 1) {
        $entity->delete();
      }
    }
  }
  
  /**
   * Permet de duppliquer un produit et ses variations.
   *
   * @param \Drupal\commerce_product\Entity\Product $Product
   *        le produit à dupliquer
   */
  public function duplicateProductEntity(\Drupal\commerce_product\Entity\Product $Product) {
    $newProduct = $Product->createDuplicate();
    $variationsIds = $newProduct->getVariationIds();
    $newProduct->setVariations([]);
    $newProduct->save();
    $productId = $newProduct->id();
    $newVariationsIds = [];
    foreach ($variationsIds as $id) {
      $ProductVariation = \Drupal\commerce_product\Entity\ProductVariation::load($id);
      $this->DefaultUpdateEntity($ProductVariation);
      $cloneProduct = $ProductVariation->createDuplicate();
      $cloneProduct->set('product_id', $productId);
      // Cette variation serra automatiquement ajouter au produit.
      $cloneProduct->save();
      $newVariationsIds[] = $cloneProduct;
    }
    if ($newVariationsIds) {
      $newProduct->setVariations($newVariationsIds);
      $newProduct->save();
    }
    return $newProduct;
  }
  
  protected function setValues(ContentEntityBase &$newEntity, array $setFields) {
    foreach ($setFields as $field_name => $value) {
      if ($newEntity->hasField($field_name)) {
        $newEntity->set($field_name, $value);
      }
    }
  }
  
  protected function DefaultUpdateEntity(&$newEntity) {
    $uid = $this->currentUser()->id();
    if (method_exists($newEntity, 'setCreatedTime'))
      $newEntity->setCreatedTime(time());
    if (method_exists($newEntity, 'setChangedTime'))
      $newEntity->setChangedTime(time());
    if (method_exists($newEntity, 'setOwnerId'))
      $newEntity->setOwnerId($uid);
    if (method_exists($newEntity, 'setPublished'))
      $newEntity->setPublished();
  }
  
  /**
   * Cette logique est utilisable principalement pour les vuejs.
   * Elle peu etre utiliser pour toutes les logiques qui souhaite avoir du JSON
   * d'une entité.
   * Permet de generer une matrice des entites avec des actions au choix tels
   * que : la duplication, un formulaire d'edition des entites.
   * ( NB: il ne fait aucune sauvegarde ).
   *
   * @param ContentEntityBase $entity
   *        // si l'$entity doit etre dupliquer ? on le fait en amont:''
   * @param array $datasJson
   */
  public function duplicateExistantReference(ContentEntityBase &$entity, array &$datasJson = [], $duplicate = true, $add_form = false) {
    $uid = $this->currentUser()->id();
    if (method_exists($entity, 'setCreatedTime'))
      $entity->setCreatedTime(time());
    if (method_exists($entity, 'setChangedTime'))
      $entity->setChangedTime(time());
    if (method_exists($entity, 'setOwnerId'))
      $entity->setOwnerId($uid);
    if (method_exists($entity, 'setPublished'))
      $entity->setPublished();
    //
    // On desactive la disponibilité du contenu sur tous les domaines.
    if (self::$field_domain_all_affiliates && $entity->hasField(self::$field_domain_all_affiliates)) {
      $entity->set(self::$field_domain_all_affiliates, false);
    }
    $values = $entity->toArray();
    foreach ($values as $k => $vals) {
      if (!empty($vals[0]['target_id'])) {
        $setings = $entity->get($k)->getSettings();
        if (empty($setings['target_type']) || in_array($setings['target_type'], $this->ignorEntity))
          continue;
        elseif (!empty($setings['target_type']))
          switch ($setings['target_type']) {
            case 'paragraph':
              foreach ($vals as $value) {
                $Paragraph = Paragraph::load($value['target_id']);
                if ($Paragraph) {
                  $Paragraph = $this->getEntityTranslate($Paragraph);
                  if ($duplicate) {
                    $CloneParagraph = $Paragraph->createDuplicate();
                    if (self::$field_domain_access && $CloneParagraph->hasField(self::$field_domain_access) && $entity->hasField(self::$field_domain_access)) {
                      $CloneParagraph->set(self::$field_domain_access, $entity->get(self::$field_domain_access)->getValue());
                    }
                  }
                  else
                    $CloneParagraph = $Paragraph;
                  
                  $subDatas = $setings;
                  $subDatas['target_id'] = $value['target_id'];
                  $ar = $CloneParagraph->toArray();
                  $subDatas['entity'] = $this->toArrayLayoutBuilderField($ar);
                  $subDatas['entities'] = [];
                  // On ajoute le formulaire si necessaire :
                  if ($add_form) {
                    $subDatas += $this->GenerateForm->getForm($setings['target_type'], $CloneParagraph->bundle(), 'default', $CloneParagraph);
                  }
                  // On verifie pour les sous entites.
                  // ( on duplique à partir de l'original ).
                  $this->duplicateExistantReference($Paragraph, $subDatas['entities'], $duplicate, $add_form);
                  $datasJson[$k][] = $subDatas;
                }
              }
              break;
            case 'node':
              foreach ($vals as $value) {
                $node = Node::load($value['target_id']);
                if ($node) {
                  $node = $this->getEntityTranslate($node);
                  if ($duplicate) {
                    $cloneNode = $node->createDuplicate();
                    // On ajoute le champs field_domain_access; ci-possible.
                    if (self::$field_domain_access && $cloneNode->hasField(self::$field_domain_access) && $entity->hasField(self::$field_domain_access)) {
                      $cloneNode->set(self::$field_domain_access, $entity->get(self::$field_domain_access)->getValue());
                    }
                    // on met à jour l'id de lutilisateur.
                    $cloneNode->setOwnerId($uid);
                  }
                  else
                    $cloneNode = $node;
                  
                  $subDatas = $setings;
                  $subDatas['target_id'] = $value['target_id'];
                  $ar = $cloneNode->toArray();
                  $subDatas['entity'] = $this->toArrayLayoutBuilderField($ar);
                  $subDatas['entities'] = [];
                  // On ajoute le formulaire si necessaire :
                  if ($add_form) {
                    $subDatas += $this->GenerateForm->getForm($setings['target_type'], $cloneNode->bundle(), 'default', $cloneNode);
                  }
                  // On verifie pour les sous entites.
                  $this->duplicateExistantReference($node, $subDatas['entities'], $duplicate, $add_form);
                  $datasJson[$k][] = $subDatas;
                }
              }
              break;
            case 'blocks_contents':
              foreach ($vals as $value) {
                $BlocksContents = BlocksContents::load($value['target_id']);
                if ($BlocksContents) {
                  if ($duplicate) {
                    $BlocksContents = $this->getEntityTranslate($BlocksContents);
                    $cloneBlocksContents = $BlocksContents->createDuplicate();
                    // On ajoute le champs field_domain_access; ci-possible.
                    if (self::$field_domain_access && $cloneBlocksContents->hasField(self::$field_domain_access) && $entity->hasField(self::$field_domain_access)) {
                      $cloneBlocksContents->set(self::$field_domain_access, $entity->get(self::$field_domain_access)->getValue());
                    }
                    // on met à jour l'id de lutilisateur.
                    $cloneBlocksContents->setOwnerId($uid);
                  }
                  else
                    $cloneBlocksContents = $BlocksContents;
                  $subDatas = $setings;
                  $subDatas['target_id'] = $value['target_id'];
                  $ar = $cloneBlocksContents->toArray();
                  $subDatas['entity'] = $this->toArrayLayoutBuilderField($ar);
                  $subDatas['entities'] = [];
                  // On ajoute le formulaire si necessaire :
                  if ($add_form) {
                    $subDatas += $this->GenerateForm->getForm($setings['target_type'], $cloneBlocksContents->bundle(), 'default', $cloneBlocksContents);
                  }
                  // On verifie pour les sous entites.
                  $this->duplicateExistantReference($BlocksContents, $subDatas['entities'], $duplicate, $add_form);
                  $datasJson[$k][] = $subDatas;
                }
              }
              break;
            case 'commerce_store':
              foreach ($vals as $value) {
                $Store = \Drupal\commerce_store\Entity\Store::load($value['target_id']);
                if ($Store) {
                  if ($duplicate) {
                    $Store = $this->getEntityTranslate($Store);
                    $cloneStore = $Store->createDuplicate();
                    // On ajoute le champs field_domain_access; ci-possible.
                    if (self::$field_domain_access && $cloneStore->hasField(self::$field_domain_access) && $entity->hasField(self::$field_domain_access)) {
                      $cloneStore->set(self::$field_domain_access, $entity->get(self::$field_domain_access)->getValue());
                    }
                    // on met à jour l'id de lutilisateur.
                    $cloneStore->setOwnerId($uid);
                  }
                  else
                    $cloneStore = $Store;
                  $subDatas = $setings;
                  $subDatas['target_id'] = $value['target_id'];
                  $ar = $cloneStore->toArray();
                  $subDatas['entity'] = $this->toArrayLayoutBuilderField($ar);
                  $subDatas['entities'] = [];
                  // On ajoute le formulaire si necessaire :
                  if ($add_form) {
                    $subDatas += $this->GenerateForm->getForm($setings['target_type'], $cloneStore->bundle(), 'default', $cloneStore);
                  }
                  // On verifie pour les sous entites.
                  $this->duplicateExistantReference($Store, $subDatas['entities'], $duplicate, $add_form);
                  $datasJson[$k][] = $subDatas;
                }
              }
              break;
            /**
             * Ce cas de figure est particulier, car on souhaite recuperer les
             * items
             * en relation avec l'id du menu.
             * Ceci est utile pour le module export_import.
             */
            case 'menu':
              foreach ($vals as $value) {
                $menuItems = $this->entityTypeManager()->getStorage('menu_link_content')->loadByProperties([
                  'menu_name' => $value['target_id']
                ]);
                $subDatas = $setings;
                $subDatas['target_id'] = $value['target_id'];
                $menu = \Drupal\system\Entity\Menu::load($value['target_id']);
                $subDatas['entity'] = $menu->toArray();
                $subDatas['entities'] = [];
                // On recupere chaque element item du menu.
                foreach ($menuItems as $menuItem) {
                  $id_item_menu = $menuItem->id();
                  $subDatas['entities']['item--' . $id_item_menu][] = [
                    'target_id' => $menuItem->id(),
                    'entity' => $menuItem->toArray(),
                    'target_type' => 'menu_link_content'
                  ];
                }
                $datasJson[$k][] = $subDatas;
              }
              break;
            case 'webform':
              foreach ($vals as $value) {
                $Webform = \Drupal\webform\Entity\Webform::load($value['target_id']);
                if ($Webform && $duplicate) {
                  /**
                   * Les webforms ont un comportement assez differents des
                   * autres
                   * entitées.
                   * il faut globalement construire le tableau avant de
                   * renvoyer.
                   * RQ1 : Certaines données (titre, description ...) sont
                   * automatquement traduit en function de la langue.
                   */
                  if ($Webform->getLangcode() != $this->getLangCode()) {
                    /**
                     * On recupere les elements non traduit et on injecte dans
                     * la
                     * conf.
                     *
                     * @var \Drupal\webform\WebformTranslationManager $wftm
                     */
                    $wftm = \Drupal::service('webform.translation_manager');
                    $elementsTranslate = $wftm->getTranslationElements($Webform, $this->getLangCode());
                    $elementsMerge = NestedArray::mergeDeepArray([
                      $Webform->getElementsDecoded(),
                      $elementsTranslate
                    ]);
                    $Webform->setElements($elementsMerge);
                  }
                  $CloneWebform = $Webform->createDuplicate();
                  // Pour les webforms, on doit ajouter le ThirdParty.
                  $domaine = 'Generate';
                  if (self::$field_domain_access) {
                    $domaine = $entity->get(self::$field_domain_access)->target_id;
                    $CloneWebform->setThirdPartySetting('webform_domain_access', self::$field_domain_access, $domaine);
                  }
                  $CloneWebform->set('title', $domaine . ' : ' . $CloneWebform->get('title'));
                  $CloneWebform->set('id', substr($Webform->id(), 0, 10) . date('YMdi') . rand(0, 9999));
                  //
                  $subDatas = $setings;
                  $subDatas['target_id'] = $value['target_id'];
                  $subDatas['entity'] = $CloneWebform->toArray();
                  //
                  if ($subDatas['entity']['langcode'] != $this->getLangCode()) {
                    $subDatas['entity']['langcode'] = $this->getLangCode();
                  }
                  $subDatas['entities'] = [];
                  // $CloneWebform->save();
                  $datasJson[$k][] = $subDatas;
                }
              }
              break;
            case 'block_content':
              $newBlockIds = [];
              foreach ($vals as $value) {
                $BlockContent = BlockContent::load($value['target_id']);
                if ($BlockContent) {
                  $BlockContent = $this->getEntityTranslate($BlockContent);
                  if ($duplicate) {
                    $CloneBlockContent = $BlockContent->createDuplicate();
                    // On ajoute le champs field_domain_access; ci-possible.
                    if (self::$field_domain_access && $CloneBlockContent->hasField(self::$field_domain_access) && $entity->hasField(self::$field_domain_access)) {
                      $dmn = $entity->get(self::$field_domain_access)->first()->getValue();
                      if ($dmn)
                        $CloneBlockContent->get(self::$field_domain_access)->setValue($dmn);
                    }
                    // On ajoute l'utilisateur courant:
                    if ($CloneBlockContent->hasField('user_id') && $uid) {
                      $CloneBlockContent->set('user_id', $uid);
                    }
                    // On met jour la date de MAJ
                    if ($CloneBlockContent->hasField('changed')) {
                      $CloneBlockContent->set('changed', time());
                    }
                    //
                    // On met à jour le champs info (car sa valeur doit etre
                    // unique).
                    if ($CloneBlockContent->hasField("info")) {
                      $val = '';
                      if ($CloneBlockContent->get('info')->first())
                        $val = $CloneBlockContent->get('info')->first()->getValue();
                      $dmn = '';
                      if (self::$field_domain_access && $entity->hasField(self::$field_domain_access)) {
                        $dmn = $entity->get(self::$field_domain_access)->first()->getValue();
                        $dmn = empty($dmn['target_id']) ? 'domaine.test' : $dmn['target_id'];
                        $dmn = $dmn . ' : ';
                      }
                      $val = $dmn . $CloneBlockContent->get('type')->target_id;
                      $CloneBlockContent->get('info')->setValue([
                        'value' => $val . ' : ' . count($newBlockIds)
                      ]);
                    }
                  }
                  else
                    $CloneBlockContent = $BlockContent;
                  //
                  $subDatas = $setings;
                  $subDatas['target_id'] = $value['target_id'];
                  $ar = $CloneBlockContent->toArray();
                  $subDatas['entity'] = $this->toArrayLayoutBuilderField($ar);
                  $subDatas['entities'] = [];
                  // On ajoute le formulaire si necessaire :
                  if ($add_form) {
                    $subDatas += $this->GenerateForm->getForm($setings['target_type'], $CloneBlockContent->bundle(), 'default', $CloneBlockContent);
                  }
                  // $CloneBlockContent->save();
                  $datasJson[$k][] = $subDatas;
                }
              }
              break;
            case 'commerce_product':
              foreach ($vals as $value) {
                /**
                 *
                 * @var \Drupal\commerce_product\Entity\Product $Product
                 */
                $Product = Product::load($value['target_id']);
                if ($Product) {
                  $Product = $this->getEntityTranslate($Product);
                  // ///
                  if ($duplicate) {
                    $CloneProduct = $Product->createDuplicate();
                    // On ajoute le champs field_domain_access; ci-possible.
                    if (self::$field_domain_access && $entity->hasField(self::$field_domain_access)) {
                      $dmn = $entity->get(self::$field_domain_access)->first()->getValue();
                      $dmn = empty($dmn['target_id']) ? null : $dmn['target_id'];
                      if ($dmn)
                        $CloneProduct->set(self::$field_domain_access, $dmn);
                    }
                    // on met à jour l'id de lutilisateur.
                    $CloneProduct->setOwnerId($uid);
                  }
                  else
                    $CloneProduct = $Product;
                  $subDatas = $setings;
                  $subDatas['target_id'] = $value['target_id'];
                  $this->duplicateProduct($Product, $CloneProduct, $duplicate, $uid, $subDatas, $add_form);
                  // On ajoute le formulaire si necessaire :
                  if ($add_form) {
                    $subDatas += $this->GenerateForm->getForm($setings['target_type'], $CloneProduct->bundle(), 'default', $CloneProduct);
                  }
                  // On verifie pour les sous entites.
                  $this->duplicateExistantReference($Product, $subDatas['entities'], $duplicate, $add_form);
                  $datasJson[$k][] = $subDatas;
                }
              }
              break;
            /**
             * Duplication des variations de produits.
             * On ne peut lancer les verifications des entites de
             * variation (i.e $this->duplicateExistantReference), sinon cela
             * entrainne une boucle infinie en produit et variations.
             */
            case 'commerce_product_variation':
              if ($k != 'default_variation') {
                foreach ($vals as $value) {
                  $ProductVariation = ProductVariation::load($value['target_id']);
                  if ($ProductVariation) {
                    $ProductVariation = $this->getEntityTranslate($ProductVariation);
                    /**
                     * On ne duplique pas les variations à ce niveau,
                     * Elle permet principalement d'inclure la variation dans le
                     * formulaire d'edition.
                     */
                    $CloneProductVariation = $ProductVariation;
                    
                    $subDatas = $setings;
                    $subDatas['target_id'] = $value['target_id'];
                    $ar = $CloneProductVariation->toArray();
                    $subDatas['entity'] = $this->toArrayLayoutBuilderField($ar);
                    $subDatas['entities'] = [];
                    // On ajoute le formulaire si necessaire :
                    if ($add_form) {
                      $subDatas += $this->GenerateForm->getForm($setings['target_type'], $CloneProductVariation->bundle(), 'default', $CloneProductVariation);
                    }
                    // L'ojectif est d'ajouter les autres dependences,
                    // On vide product_id afin d'eviter de tourner en rond.
                    $ProductVariation->set('product_id', []);
                    $this->duplicateExistantReference($ProductVariation, $subDatas['entities'], $duplicate, $add_form);
                    /**
                     * On duplique ou ajoute le formulaire pour les entites
                     * importantes.
                     */
                    $datasJson[$k][] = $subDatas;
                  }
                }
              }
              break;
            /**
             * Recuperation des entites de configurations.
             */
            case 'commerce_store_type':
            case 'taxonomy_vocabulary':
              $ortherEntityConfig = $this->entityTypeManager()->getStorage($setings['target_type']) ? $this->entityTypeManager()->getStorage($setings['target_type'])->load($value['target_id']) : null;
              if ($ortherEntityConfig) {
                $subDatas = $setings;
                $subDatas['target_id'] = $value['target_id'];
                $subDatas['entity'] = $ortherEntityConfig->toArray();
                $subDatas['entities'] = [];
                $datasJson[$k][] = $subDatas;
              }
              break;
            /**
             * Duplication des autres entites storages necesaires.
             */
            case 'commerce_product_attribute_value':
            case 'commerce_product_attribute':
            case 'commerce_currency':
            case 'taxonomy_term':
              foreach ($vals as $value) {
                $ortherEntity = $this->entityTypeManager()->getStorage($setings['target_type']) ? $this->entityTypeManager()->getStorage($setings['target_type'])->load($value['target_id']) : null;
                if ($ortherEntity && $ortherEntity instanceof ContentEntityBase) {
                  if ($duplicate) {
                    $ortherEntity = $this->getEntityTranslate($ortherEntity);
                    $cloneOrtherEntity = $ortherEntity->createDuplicate();
                    // On ajoute le champs field_domain_access; ci-possible.
                    if (self::$field_domain_access && $cloneOrtherEntity->hasField(self::$field_domain_access) && $entity->hasField(self::$field_domain_access)) {
                      $cloneOrtherEntity->set(self::$field_domain_access, $entity->get(self::$field_domain_access)->getValue());
                    }
                    // on met à jour l'id de lutilisateur.
                    $cloneOrtherEntity->setOwnerId($uid);
                  }
                  else
                    $cloneOrtherEntity = $ortherEntity;
                  $subDatas = $setings;
                  $subDatas['target_id'] = $value['target_id'];
                  $ar = $cloneOrtherEntity->toArray();
                  $subDatas['entity'] = $this->toArrayLayoutBuilderField($ar);
                  $subDatas['entities'] = [];
                  // On ajoute le formulaire si necessaire :
                  if ($add_form) {
                    $subDatas += $this->GenerateForm->getForm($setings['target_type'], $cloneOrtherEntity->bundle(), 'default', $cloneOrtherEntity);
                  }
                  // On verifie pour les sous entites.
                  $this->duplicateExistantReference($ortherEntity, $subDatas['entities'], $duplicate, $add_form);
                  $datasJson[$k][] = $subDatas;
                }
              }
              break;
            default:
              $message = " Entité non traitée, field :" . $k . ', type : ' . $setings['target_type'];
              $this->messenger()->addError($message);
              \Drupal::logger('apivuejs')->alert($message);
              break;
          }
      }
      /**
       * Error 1: Le champs layout_builder__layout ne se duplique pas le contenu
       * est ["section": {}].
       * Error 2: La modification via le crayon supprime egalement cette
       * configuration.
       * Correstion :
       */
      elseif ($k == 'layout_builder__layout' && !empty($vals)) {
        // dump($vals);
      }
    }
    // dump($datasJson);
  }
  
  /**
   * NB: cette approche est adapté pour vuejs.( voir le module :
   * formatage_models )
   * Permet de cloner un produit avec ses variations.
   * (NB: le clone du produit est sauvegarder car les variations ont besoin de
   * l'id ).s
   *
   * @see https://git.drupalcode.org/project/quick_node_clone/-/tree/8.x-1.x/
   *      c'est un module interressant pour cloner un node. (on doit essayer de
   *      comprendre l'approche ).
   * @param ContentEntityBase $Product
   * @param ContentEntityBase $CloneProduct
   * @param Boolean $duplicate
   * @param int $uid
   * @param array $subDatas
   */
  function duplicateProduct(ContentEntityBase $Product, ContentEntityBase $CloneProduct, bool $duplicate, int $uid, array &$subDatas = [], $add_form = false) {
    if ($duplicate) {
      // On met jour la date de MAJ
      $CloneProduct->setCreatedTime(time());
      $CloneProduct->setChangedTime(time());
      // On supprime les variations dans le clone, car il
      // appartiennent
      // au produit precedent.
      $CloneProduct->setVariations([]);
      $CloneProduct->save();
    }
    
    //
    
    $subDatas['entity'] = $CloneProduct->toArray();
    $subDatas['entities'] = [];
    
    /**
     * Cette etape n'a de sens que si on duplique un produit.
     * ( Si non, pas necessaire ).
     */
    if ($duplicate) {
      $cloneProducdId = $CloneProduct->id();
      // On duplique les variations à partir du produit d'origine.
      $variationsIds = $Product->getVariationIds();
      $newVariations = [];
      if (!empty($variationsIds)) {
        $subDatas['entities']['variations'] = [];
        foreach ($variationsIds as $variationId) {
          $variation = ProductVariation::load($variationId);
          if ($variation) {
            $cloneVariation = $variation->createDuplicate();
            $cloneVariation->set('product_id', $cloneProducdId);
            // on met à jour le SKU
            $cloneVariation->set('sku', $CloneProduct->id() . '-' . $cloneVariation->getSku());
            // on met à jour le domain si necessaire
            if (self::$field_domain_access && $cloneVariation->hasField(self::$field_domain_access) && $CloneProduct->hasField(self::$field_domain_access)) {
              $cloneVariation->set(self::$field_domain_access, $CloneProduct->get(self::$field_domain_access)->getValue());
            }
            // on met à jour l'id de lutilisateur.
            $cloneVariation->setOwnerId($uid);
            //
            $cloneVariation->save();
            $newVariations[] = $cloneVariation->id();
            // Ajout de la variations dans le formulaire
          }
        }
        $CloneProduct->setVariations($newVariations);
      }
      $CloneProduct->save();
      // On met à jour la valeur de entity car on a ajouté les
      // variations dupliquées dans $CloneProduct.
      $ar = $CloneProduct->toArray();
      $subDatas['entity'] = $this->toArrayLayoutBuilderField($ar);
    }
  }
  
  /**
   * Cette fonction a pour objectif de recuperer le json du layout_builder.
   * La fonction toArray de l'entité ne transmet pas pour le moment les bonnes
   * valeurs (en fait c'est vide),
   *
   * @see https://www.drupal.org/project/drupal/issues/2942975
   */
  function toArrayLayoutBuilderField(array &$entity) {
    if (!empty($entity['layout_builder__layout'])) {
      foreach ($entity['layout_builder__layout'] as $i => $sections) {
        foreach ($sections as $s => $section) {
          if (is_object($section)) {
            /**
             *
             * @var \Drupal\layout_builder\Section $section
             */
            $entity['layout_builder__layout'][$i][$s] = $section->toArray();
          }
        }
      }
    }
    return $entity;
  }
  
  function getEntityTranslate(ContentEntityBase $entity) {
    $this->getLangCode();
    if ($entity->hasTranslation($this->lang_code)) {
      return $entity->getTranslation($this->lang_code);
    }
    else
      return $entity;
  }
  
  protected function getLangCode() {
    if (!$this->lang_code)
      $this->lang_code = \Drupal::languageManager()->getCurrentLanguage()->getId();
    return $this->lang_code;
  }
  
  /**
   *
   * @param ContentEntityBase $entity
   * @param array $datasJson
   */
  function saveDuplicateEntities(ContentEntityBase &$entity, array &$datasJson = []) {
    //
  }
  
  public function getDuplicableEntitiesTypes() {
    return $this->duplicable_entities_types;
  }
}
