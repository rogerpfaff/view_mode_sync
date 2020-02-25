<?php

namespace Drupal\view_mode_sync\Form;

use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a view_mode_sync form.
 */
class ViewModeSyncForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $configStorage;

  /**
   * The entity display repository service.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $entityDisplayRepository;

  /**
   * The entity type bundle info interface
   *
   * @var |Drupal|Core|Entity|EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfoInterface;

  /*
   * @var |Drupal|Core|Path|Path|Validator
   */
  protected $pathValidator;

  /**
   * Tracks the valid config entity type definitions.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface[]
   */
  protected $definitions = [];

  /**
   * Constructs a new ConfigSingleImportForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\StorageInterface $config_storage
   *   The config storage.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository
   *   The entity display repository.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info_interface
   *
   * @param |Drupal|Core|Path|Path|Validator $path_validator;
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    StorageInterface $config_storage,
    EntityDisplayRepositoryInterface $display_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info_interface
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configStorage = $config_storage;
    $this->entityDisplayRepository = $display_repository;
    $this->entityTypeBundleInfoInterface = $entity_type_bundle_info_interface;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.storage'),
      $container->get('entity_display.repository'),
      $container->get('entity_type.bundle.info')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'view_mode_sync_form_sync';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $entity_types = static::getEntityTypes();

    if (empty($form_state->getValue('entity_type'))) {
      $selected_entity_type = key($entity_types);
    }
    else {
      $selected_entity_type = $form_state->getValue('entity_type');
    }

    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Select the entity type'),
      '#options' => $entity_types,
      '#default_value' => $selected_entity_type,
      '#ajax' => [
        'callback' => '::updateSourceBundle',
        'wrapper' => 'edit-source-bundle-wrapper'
      ]
    ];

    $bundles = $target_bundles = static::findBundles($selected_entity_type);
    if (empty($form_state->getValue('target_bundles'))) {
      $selected_bundle = key($bundles);
    }
    else {
      $selected_bundle = $form_state->getValue('target_bundles');
    }

    $form['source_bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Select the source bundle'),
      '#prefix' => '<div id="edit-source-bundle-wrapper">',
      '#suffix' => '</div>',
      '#options' => static::findBundles($selected_entity_type),
      '#default_value' => $selected_bundle,
      '#ajax' => [
        'callback' => '::updateDisplayMode',
        'wrapper' => 'edit-display-mode-wrapper'
      ],
    ];

    $display_modes = static::findDisplayModes($selected_entity_type, $selected_bundle);
    if (empty($form_state->getValue('display_mode'))) {
      $selected_display_mode = key($display_modes);
    }
    else {
      $selected_display_mode = $form_state->getValue('display_mode');
    }
    $form['display_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Select the source display mode'),
      '#prefix' => '<div id="edit-display-mode-wrapper">',
      '#suffix' => '</div>',
      '#default_value' => $selected_display_mode,
      '#options' => $display_modes,
      '#ajax' => [
        'callback' => '::updateTargetBundles',
        'wrapper' => 'edit-target-bundle-wrapper'
      ],
    ];

    unset($target_bundles[$selected_bundle]);
    if (empty($form_state->getValue('target_bundles'))) {
      $selected_target_bundles = key($target_bundles);
    }
    else {
      $selected_target_bundles = $form_state->getValue('target_bundles');
    }
    $form['target_bundles'] = [
      '#type' => 'select',
      '#title' => $this->t('Select the target bundles'),
      '#prefix' => '<div id="edit-target-bundle-wrapper">',
      '#suffix' => '</div>',
      '#multiple' => TRUE,
      '#default_value' => $selected_target_bundles,
      '#options' => $target_bundles,
    ];

    $form['fields'] = [
      '#type' => '#markup',
      '#markup' => $this->t('Here will hopefully come an option to create fields.')
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send'),
    ];

    return $form;
  }

  /*
   * Handles the source bundle switcher
   */
  public function updateSourceBundle($form, FormStateInterface $form_state) {
    return $form['source_bundle'];
  }

  /**
   * Handle the display mode options
   */
  public function updateDisplayMode($form, FormStateInterface $form_state) {
    return $form['display_mode'];
  }

  public function updateTargetBundles($form, FormStateInterface $form_state) {
    return $form['target_bundles'];
  }

  /**
   * @return array
   */
  public function getEntityTypes() {
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type => $definition) {
      if ($definition->entityClassImplements(ContentEntityInterface::class)) {
        $this->definitions[$entity_type] = $definition;
      }
    }
    $entity_types = array_map(function (EntityTypeInterface $definition) {
      return $definition->getLabel();
    }, $this->definitions);
    // Sort the entity types by label, then add the simple config to the top.
    uasort($entity_types, 'strnatcasecmp');
    return $entity_types;
  }

  /**
   * Handles the target bundle switcher
   */
  public function updateTargetBundle($form, FormStateInterface $form_state) {
    return $form['target_bundle'];
  }

  protected function findBundles($entity_type) {
    $names = [];
    foreach ($this->entityTypeBundleInfoInterface->getBundleInfo($entity_type) as $bundle => $data) {
      $names[$bundle] = $data['label'];
    }
    return $names;
  }

  protected function findDisplayModes($entity_type, $bundle) {
    $names = [];
    $this->entityDisplayRepository->
    foreach ($this->entityDisplayRepository->getFormModeOptions($entity_type) as $form_mode => $label) {
      $names[$this->t('Form modes')->__toString()]['form.' . $form_mode] = $label;
    }
    foreach ($this->entityDisplayRepository->getViewModeOptions($entity_type) as $view_mode => $label) {
      $names[$this->t('View modes')->__toString()]['view.' . $view_mode] = $label;
    }
    return $names;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->messenger()->addStatus($this->t('The message has been sent.'));
    $form_state->setRedirect('<front>');
  }

}
