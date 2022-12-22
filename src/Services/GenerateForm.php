<?php

namespace Drupal\apivuejs\Services;

use Drupal\Core\Controller\ControllerBase;

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
      throw new \Exception("Le type d'entité n'exsite pas : " . $entity_type_id);
    if (!$EntityStorage->getEntityType()->getBaseTable())
      throw new \Exception("Le type d'entité de configuration ne sont pas pris en compte : " . $entity_type_id);
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
    $fields = $entity->toArray();
    
    /**
     *
     * @var EntityFieldManager $entityManager
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
     *
     * @deprecated ce champs va etre supprimer à l'avenir. il est garder
     *             uniquement afin de reduire le temps de MAJ du code.
     * @var array $form
     */
    $form = [];
    $form_sort = [];
    foreach ($components as $k => $value) {
      if (!empty($Allfields[$k])) {
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
        $form_sort[] = $field;
        $form[$k] = $field;
      }
    }
    // Trie un tableau par la propriété weight.
    usort($form_sort, '\Drupal\Component\Utility\SortArray::sortByWeightElement');
    return [
      'form' => $form,
      'model' => $fields,
      'form_sort' => $form_sort
    ];
  }
  
  /**
   *
   * @param array $settings
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   */
  protected function translateConfigField(array $settings) {
    if (!empty($settings['list_options']))
      foreach ($settings['list_options'] as $k => $val) {
        $settings['list_options'][$k]['label'] = $this->t($val['label']);
        if (!empty($val['description']['value']))
          $settings['list_options'][$k]['description']['value'] = $this->t($val['description']['value']);
      }
    return $settings;
  }
  
}