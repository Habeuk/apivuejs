<?php

namespace Drupal\apivuejs\Services;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityAccessControlHandler;

/**
 *
 * @author Stephane
 *         Permet de generer un formulaire valide tout en tenant compte des
 *         autorisations d'acces aux entites et au champs.
 */
class GenerateForm extends ControllerBase {
  /**
   *
   * @var array
   */
  protected static $field_un_use_paragrph = [
    'id',
    'revision_id',
    'langcode',
    'uuid',
    'status',
    'created',
    'type',
    'parent_id',
    'parent_type',
    'parent_field_name',
    'parent_field_name',
    'default_langcode',
    'revision_default',
    'revision_translation_affected',
    'revision_translation_affected',
    "revision_translation_affected",
    'content_translation_source',
    'content_translation_outdated',
    'content_translation_changed'
  ];
  
  /**
   * Retourne les champs des entites de type contentEntity.
   */
  public function getForm($entity_type_id, $bundle = null, $view_mode = 'default', $entity = null) {
    /**
     *
     * @var \Drupal\Core\Config\Entity\ConfigEntityStorage $EntityStorage
     */
    $EntityStorage = $this->entityTypeManager()->getStorage($entity_type_id);
    if (empty($EntityStorage))
      throw new \Exception(" Le type d'entité n'exsite pas : " . $entity_type_id);
    if (!$EntityStorage->getEntityType()->getBaseTable())
      throw new \Exception(" Le type d'entité de configuration ne sont pas pris en compte : " . $entity_type_id);
    if (!$entity) {
      if ($bundle && $bundle != $entity_type_id)
        $entity = $EntityStorage->create([
          'type' => $bundle
        ]);
      else {
        $bundle = $entity_type_id;
        $entity = $EntityStorage->create();
      }
    }
    // on doit charger les données en fonction de la langue encours.
    $lang_code = \Drupal::languageManager()->getCurrentLanguage()->getId();
    if ($entity->hasTranslation($lang_code)) {
      $fields = $entity->getTranslation($lang_code)->toArray();
    }
    else
      $fields = $entity->toArray();
    
    /**
     *
     * @var \Drupal\Core\Entity\EntityFieldManager $entityManager
     */
    $entityManager = \Drupal::service('entity_field.manager');
    $Allfields = $entityManager->getFieldDefinitions($entity_type_id, $bundle);
    
    /**
     * ( NB )
     *
     * @var \Drupal\Core\Entity\Entity\EntityFormDisplay $entity_form_view
     */
    $entity_form_view = $this->entityTypeManager()->getStorage('entity_form_display')->load($entity_type_id . '.' . $bundle . '.' . $view_mode);
    if (!$entity_form_view) {
      $entity_form_view = $this->entityTypeManager()->getStorage('entity_form_display')->create([
        'bundle' => $bundle,
        'targetEntityType' => $entity_type_id
      ]);
    }
    $components = $entity_form_view->getComponents();
    /**
     * Mise en place de la verification.
     */
    $EntityAccessControlHandler = new EntityAccessControlHandler($entity->getEntityType());
    /**
     *
     * @deprecated ce champs va etre supprimer à l'avenir. il est garder
     *             uniquement afin de reduire le temps de MAJ du code.
     * @var array $form
     */
    $form = [];
    $form_sort = [];
    foreach ($components as $k => $value) {
      if (!empty($Allfields[$k]) && $EntityAccessControlHandler->fieldAccess('edit', $Allfields[$k])) {
        /**
         *
         * @var \Drupal\field\Entity\FieldConfig $definitionField
         */
        $definitionField = $Allfields[$k];
        $field = [
          'name' => $k,
          'label' => $definitionField->getLabel(),
          'description' => $definitionField->getDescription(),
          'cardinality' => 1
        ] + $value;
        $field['definition_settings'] = $definitionField->getSettings();
        if (method_exists($definitionField, 'getFieldStorageDefinition')) {
          $field['cardinality'] = $definitionField->getFieldStorageDefinition()->getCardinality();
          $field['constraints'] = $definitionField->getFieldStorageDefinition()->getConstraints();
        }
        // pour le champs image, on a besoin de connaitre le nom du module (ici,
        // cela correspond à l'entite), cela permet de supprimer le champs au
        // niveau de file_usage.
        if ((!empty($field['definition_settings']['handler']) && $field['definition_settings']['handler'] == 'default:file') || (!empty($field['definition_settings']['target_type']) && $field['definition_settings']['target_type'] == 'file')) {
          $field['definition_settings']['module_name'] = $entity_type_id;
        }
        /**
         * Dans le cas ou on a des données dans allowed_values_function on
         * recupere ces données et on les passes dans allowed_values.
         * ( ceci devrait fonctionner pour la pluspart des cas ).
         *
         * @see https://drupal.stackexchange.com/questions/294338/how-do-i-get-all-the-options-of-a-field
         */
        if (!empty($field['definition_settings']['allowed_values_function'])) {
          $field['definition_settings']['allowed_values'] = options_allowed_values($definitionField, $entity);
        }
        
        $form_sort[] = $field;
        $form[$k] = $field;
      }
    }
    // Trie un tableau par la propriété weight.
    usort($form_sort, '\Drupal\Component\Utility\SortArray::sortByWeightElement');
    // traitement des champs de layout_builder__layout. ( ces champs ne sont pas
    // traiter ici pour le moment ).
    /**
     * Error signaler.
     * ( il faudra trouver une autre approche ).
     *
     * @var \Drupal\vuejs_entity\Services\DuplicateEntityReference $duplicate
     */
    // $duplicate = \Drupal::service('vuejs_entity.duplicate.entity');
    // $duplicate->toArrayLayoutBuilderField($fields);
    return [
      'form' => $form, // @deprecated à supprimer 2x
      'model' => $fields, // @deprecated à supprimer 2x
      'entity' => $fields, // Contient les données qui vont etre MAJ.
      'form_sort' => $form_sort, // contient les champs rangés.
      'target_type' => $entity_type_id, // l'id de l'entité.
      'label' => $entity->label()
    ];
  }
  
}