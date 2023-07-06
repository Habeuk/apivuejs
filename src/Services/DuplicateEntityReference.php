<?php

namespace Drupal\apivuejs\Services;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Drupal\block_content\Entity\BlockContent;
use Drupal\commerce_product\Entity\Product;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\vuejs_entity\Event\DuplicateEntityEvent;
use Drupal\apivuejs\Services\GenerateForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\blockscontent\Entity\BlocksContents;
use Drupal\Core\Entity\EntityBase;
use Drupal\Component\Utility\NestedArray;

class DuplicateEntityReference extends ControllerBase {
  protected static $field_domain_access = null;
  protected static $field_domain_all_affiliates = null;
  /**
   *
   * @var \Drupal\apivuejs\Services\GenerateForm
   */
  protected $GenerateForm;
  
  function __construct(GenerateForm $GenerateForm) {
    $this->GenerateForm = $GenerateForm;
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
    'taxonomy_term',
    'file',
    'commerce_store',
    'commerce_product_type',
    'node_type',
    'blocks_contents_type'
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
  
  /**
   * Permet de generer une matrice des entites avec des actions au choix tels
   * que : la duplication, un formulaire d'edition des entites.
   * ( NB: il ne fait aucune sauvegarde ).
   *
   * @param ContentEntityBase $entity
   *        // si l'$entity doit etre dupliquer ? on le fait en amont:''
   * @param array $datasJson
   */
  public function duplicateExistantReference(ContentEntityBase &$entity, array &$datasJson = [], $duplicate = true, $add_form = false) {
    //
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
    // if (!empty($values['layout_builder__layout'])) {
    // \Stephane888\Debug\debugLog::$max_depth = 10;
    // \Stephane888\Debug\debugLog::kintDebugDrupal($values['layout_builder__layout'],
    // 'layout_builder__layout', true);
    // }
    // \Stephane888\Debug\debugLog::kintDebugDrupal($values,
    // 'duplicateExistantReference', true);
    // Get the event_dispatcher service and dispatch the event.
    // $event_dispatcher = \Drupal::service('event_dispatcher');
    foreach ($values as $k => $vals) {
      if (!empty($vals[0]['target_id'])) {
        $setings = $entity->get($k)->getSettings();
        
        if (empty($setings['target_type']) || in_array($setings['target_type'], $this->ignorEntity))
          continue;
        // Duplication des paragraph
        /**
         * La duplication de paragraphe ajoute une duplication de l'entité à
         * dupliquer.
         * => la struture du champs contient en plus une entré "entity"
         * Donc, on a [{target_id:null, entity:
         * \Drupal\paragraphs\Entity\Paragraph}],
         * On a chosit de regler ce probleme via le js, qui doit vider le champs
         * et ajouter les nouveaux ids.
         */
        elseif (!empty($setings['target_type']) && $setings['target_type'] == 'paragraph') {
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
        }
        // Duplication des sous nodes.
        elseif (!empty($setings['target_type']) && $setings['target_type'] == 'node') {
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
        }
        // Duplication des sous nodes.
        elseif (!empty($setings['target_type']) && $setings['target_type'] == 'blocks_contents') {
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
        }
        // Duplication des formulaires.
        elseif (!empty($setings['target_type']) && $setings['target_type'] == 'webform') {
          foreach ($vals as $value) {
            $Webform = \Drupal\webform\Entity\Webform::load($value['target_id']);
            // dump($value['target_id']);
            // /**
            // *
            // * @var \Drupal\locale\LocaleConfigManager $local_config_manager
            // */
            // $local_config_manager =
            // \Drupal::service('locale.config_manager');
            // // dump($local_config_manager->get
            // /**
            // *
            // * @var \Drupal\language\ConfigurableLanguageManagerInterface
            // $language_manager
            // */
            // $language_manager = \Drupal::service('language_manager');
            // /**
            // *
            // * @var \Drupal\language\Config\LanguageConfigOverride $config
            // */
            // $config =
            // $language_manager->getLanguageConfigOverride($this->getLangCode(),
            // $Webform->getConfigDependencyName());
            // dump($config->get('elements'));
            // dump($Webform->getElementsRaw());
            // dump($Webform->getElementsDecoded());
            // // NestedArray::mergeDeepArray([]);
            // /**
            // *
            // * @var \Drupal\webform\WebformTranslationManager $wftm
            // */
            // $wftm = \Drupal::service('webform.translation_manager');
            // $elementsTranslate = $wftm->getTranslationElements($Webform,
            // $this->getLangCode());
            // $elementsMerge = NestedArray::mergeDeepArray([
            // $Webform->getElementsDecoded(),
            // $elementsTranslate
            // ]);
            // $Webform->setElements($elementsMerge);
            // dump($Webform->toArray());
            
            //
            if ($Webform && $duplicate) {
              /**
               * Les webforms ont un comportement assez differents des autres
               * entitées.
               * il faut globalement construire le tableau avant de renvoyer.
               * RQ1 : Certaines données (titre, description ...) sont
               * automatquement traduit en function de la langue.
               */
              if ($Webform->getLangcode() != $this->getLangCode()) {
                /**
                 * On recupere les elements non traduit et on injecte dans la
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
        }
        // Duplication des sous blocs.
        elseif (!empty($setings['target_type']) && $setings['target_type'] == 'block_content') {
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
        }
        // Dupliquer les produits.
        elseif (!empty($setings['target_type']) && $setings['target_type'] == 'commerce_product') {
          // Pour le type produit, on doit Ajouter le role à l'utilisateur.
          if (!empty($this->currentUser()->id()) && !in_array('manage_ecommerce', $this->currentUser()->getRoles())) {
            $user = \Drupal\user\Entity\User::load($this->currentUser->id());
            $user->addRole('manage_ecommerce');
            $user->save();
            $this->messenger()->addMessage(' Le role vendor a été automatiquement ajouté ');
          }
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
              $this->duplicateProduct($Product, $CloneProduct, $duplicate, $uid, $subDatas);
              // On ajoute le formulaire si necessaire :
              if ($add_form) {
                $subDatas += $this->GenerateForm->getForm($setings['target_type'], $CloneProduct->bundle(), 'default', $CloneProduct);
              }
              // On verifie pour les sous entites.
              $this->duplicateExistantReference($Product, $subDatas['entities'], $duplicate, $add_form);
              $datasJson[$k][] = $subDatas;
              //
              // $newProducts[] = [
              // 'target_id' => $cloneProducdId
              // ];
            }
          }
        }
        /**
         * Duplication des variations de produits.
         * On ne peut lancer les verifications des entites de
         * variation (i.e $this->duplicateExistantReference), sinon cela
         * entrainne une boucle infinie en produit et variations.
         */
        elseif (!empty($setings['target_type']) && $setings['target_type'] == 'commerce_product_variation' && $k != 'default_variation') {
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
              /**
               * On duplique ou ajoute le formulaire pour les entites
               * importantes.
               */
              $datasJson[$k][] = $subDatas;
            }
          }
        }
        else {
          \Drupal::logger('vuejs_entity')->alert(" Entité non traitée, field :" . $k . ', type : ' . $setings['target_type']);
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
   * @param array $value
   * @param array $subDatas
   */
  function duplicateProduct(ContentEntityBase $Product, ContentEntityBase $CloneProduct, bool $duplicate, int $uid, array &$subDatas = []) {
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
          /**
           *
           * @var \Drupal\layout_builder\Section $section
           */
          $entity['layout_builder__layout'][$i][$s] = $section->toArray();
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
  
}