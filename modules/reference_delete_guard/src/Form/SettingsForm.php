<?php

declare(strict_types=1);

namespace Drupal\reference_delete_guard\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings: which bundles are protected from deletion while referenced.
 */
class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reference_delete_guard_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['reference_delete_guard.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['protected'] = [
      '#type' => 'details',
      '#title' => $this->t('Protected content'),
      '#description' => $this->t('Items of the selected types cannot be deleted while any other content references them.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $current = (array) $this->config('reference_delete_guard.settings')->get('protected');
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if (!$entity_type instanceof ContentEntityTypeInterface) {
        continue;
      }
      $options = [];
      foreach ($this->bundleInfo->getBundleInfo($entity_type_id) as $bundle => $info) {
        $options[$entity_type_id . ':' . $bundle] = $info['label'];
      }
      if ($options) {
        $form['protected'][$entity_type_id] = [
          '#type' => 'checkboxes',
          '#title' => $entity_type->getLabel(),
          '#options' => $options,
          '#default_value' => array_values(array_intersect($current, array_keys($options))),
        ];
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $protected = [];
    foreach ((array) $form_state->getValue('protected') as $selected) {
      $protected = array_merge($protected, array_values(array_filter((array) $selected)));
    }
    sort($protected);

    $this->config('reference_delete_guard.settings')->set('protected', $protected)->save();
    parent::submitForm($form, $form_state);
  }

}
