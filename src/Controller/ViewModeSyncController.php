<?php

namespace Drupal\view_mode_sync\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityInterface;
use Drupal\field\Entity\FieldConfig;

/**
 * Returns responses for View mode sync routes.
 */
class ViewModeSyncController extends ControllerBase {

  public $entity_type;

  public $bundle;

  public $mode;

  public $context;

  /**
   * Builds the response.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function build($entity_type, $source_bundle, $source_display, $target_bundle, $target_display) {
    $this->entity_type = 'commerce_product';
    $this->bundle = 'hedoga_default';
    $this->mode = 'default';
    $this->context = 'form';

    if ($this->context == 'form') {
      $source_display = EntityFormDisplay::load($this->entity_type . '.' . $this->bundle . '.' . $this->mode);
    }
    elseif ($this->context == 'view') {
      $source_display = EntityViewDisplay::load($this->entity_type . '.' . $this->bundle . '.' . $this->mode);
    }

    if (!isset($source_display)) {
      $build['content'] = [
        '#type' => 'item',
        '#markup' => $this->t('Source display failed to load.'),
      ];
      return $build;
    }

    $target_bundles = [
      'medical',
      'food',
      'food_supplements',
      'pharmaceutical',
      'cosmetic'
    ];

    foreach ($target_bundles as $target_bundle) {
      if ($this->context == 'form') {
        $target_display = EntityFormDisplay::load($this->entity_type . '.' . $target_bundle . '.' . $this->mode);
      }
      elseif ($this->context == 'view') {
        $target_display = EntityViewDisplay::load($this->entity_type . '.' . $target_bundle . '.' . $this->mode);
      }

      // sync field groups
      $this->syncFieldGroups($target_display, $source_display->get('third_party_settings')['field_group'], $target_bundle);

      // sync fields
      $source_fields = $source_display->getComponents();
      $this->syncFields($target_display, $source_fields);
    }

    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('It worked! Export the configuration after checking everything or not.'),
    ];

    return $build;
  }

  /**
   * @param \Drupal\Core\Entity\EntityInterface $target_display
   * @param array $source_field_groups
   * @param string $target_bundle
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  function syncFieldGroups(EntityInterface $target_display, array $source_field_groups, string $target_bundle) {
    $target_field_groups = $disable_groups = $target_display->get('third_party_settings')['field_group'];
    foreach ($source_field_groups as $source_field_group_name => $source_data) {
      if (!isset($target_field_groups[$source_field_group_name])) {
        $field_group = (object) [
          'group_name' => $source_field_group_name,
          'entity_type' => $this->entity_type,
          'bundle' => $target_bundle,
          'mode' => $this->mode,
          'context' => $this->context,
          'children' => $source_data['children'],
          'parent_name' => $source_data['parent_name'],
          'weight' => $source_data['weight'],
          'label' => $source_data['label'],
          'format_type' => $source_data['format_type'],
          'format_settings' => $source_data['format_settings'],
          'region' => 'content',
        ];
        field_group_group_save($field_group);
        \Drupal::messenger()->addMessage('Created fieldgroup @fieldgroup on @target_bundle.', [
          '@fieldgroup' => $source_field_group_name,
          '@target_bundle' => $target_bundle,
        ]);
      }
      else {
        // update the field group settings
        $target_field_group = field_group_load_field_group($source_field_group_name, $this->entity_type, $target_bundle, $this->context, $this->mode);
        $source_field_group = field_group_load_field_group($source_field_group_name, $this->entity_type, $this->bundle, $this->context, $this->mode);

        if (!($this->compareFieldGroups($target_field_group, $source_field_group))) {
          $target_field_group->children = $source_field_group->children;
          $target_field_group->parent_name = $source_field_group->parent_name;
          $target_field_group->weight = $source_field_group->weight;
          $target_field_group->label = $source_field_group->label;
          $target_field_group->format_type = $source_field_group->format_type;
          $target_field_group->format_settings = $source_field_group->format_settings;
          field_group_group_save($target_field_group);
        }
        \Drupal::messenger()->addMessage('Updated fieldgroup @fieldgroup on @target_bundle.', [
          '@fieldgroup' => $source_field_group_name,
          '@target_bundle' => $target_bundle,
        ]);
      }
      unset($disable_groups[$source_field_group_name]);
    }
    foreach($disable_groups as $group_name => $group) {
      $target_field_group = field_group_load_field_group($group_name, $this->entity_type, $target_bundle, $this->context, $this->mode);
      $target_field_group->region = 'hidden';
      field_group_group_save($target_field_group);
    }
  }

  /**
   * Sync the field settings.
   *
   * @param \Drupal\Core\Entity\EntityInterface $target_display
   * @param array $source_fields
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  function syncFields(EntityInterface $target_display, array $source_fields) {
    $target_fields = $disable_fields = $target_display->getComponents();
    foreach ($source_fields as $source_field_name => $source_field_data) {
      $target_field = FieldConfig::loadByName($this->entity_type, $target_display->getTargetBundle(), $source_field_name);
      $source_field = FieldConfig::loadByName($this->entity_type, $this->bundle, $source_field_name);
      // if we can't load any of the fields they are no custom fields and will not be touched
      // TODO: check this for all fields.
      if (!isset($target_field) && !isset($source_field)) {
        unset($disable_fields[$source_field_name]);
        continue;
      }
      // if we get no target and a source the field does not exist.
      if (!isset($target_field) && isset($source_field)) {
        \Drupal::messenger()->addMessage($this->t(
          '@field does not exist in @display',
          [
            '@field' => $source_field_name,
            '@display' => $target_display->getOriginalId(),
          ]
        ));
        // TODO: Create the field if selected in the UI that is not currently there.
        /*
                FieldConfig::create([
                    'field_name'   => $source_field_name,
                    'entity_type'  => $this->entity_type,
                    'bundle'       => $target_display->getTargetBundle(),
                    'label'        => $source_field->label(),
                    'required'     => $source_field->get('required'),
                    'translatable' => $source_field->get('translatable'),
                    'description'  => t($source_field->get('description')),
                  ])
                  ->save();
                  // TODO: Update the widget settings and the display component settings.
        */
      }
      else {
        // update the widget type

        // update the source_field.
        $update = [
          'type' => $source_field_data['type'],
          'weight' => $source_field_data['weight'],
          'settings' => $source_field_data['settings'],
          'third_party_settings' => $source_field_data['third_party_settings']
        ];
        $target_display->setComponent($source_field_name, $update);
        \Drupal::messenger()->addMessage($this->t('Field @field updated in @target_display', [
            '@field' => $source_field_name,
            '@target_display' => $target_display->getOriginalId(),
          ]
        ));
      }
      unset($disable_fields[$source_field_name]);
    }
    foreach ($disable_fields as $field_name => $field_data) {
      // move the source_field to the disable area
      $target_display->removeComponent($field_name);
    }
    $target_display->save();
  }

  /**
   * Compare to fieldgroups for specific similarity.

   * @param $group1
   * @param $group2
   */
  private function compareFieldGroups($group1, $group2) {
    if ($group1->children !== $group2->children) {
      return FALSE;
    }
    if ($group1->parent_name !== $group2->parent_name) {
      return FALSE;
    }
    if ($group1->weight !== $group2->weight) {
      return FALSE;
    }
    if ($group1->label !== $group2->label) {
      return FALSE;
    }
    if ($group1->format_type !== $group2->format_type) {
      return FALSE;
    }
    if ($group1->format_settings !== $group2->format_settings) {
      return FALSE;
    }
    return TRUE;
  }
}
