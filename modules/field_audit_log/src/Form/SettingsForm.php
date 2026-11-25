<?php

declare(strict_types=1);

namespace Drupal\field_audit_log\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings: which bundles are tracked and how long entries are kept.
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
    return 'field_audit_log_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['field_audit_log.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('field_audit_log.settings');

    $form['tracked'] = [
      '#type' => 'details',
      '#title' => $this->t('Tracked content'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $current = (array) $config->get('tracked');
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if (!$entity_type instanceof ContentEntityTypeInterface) {
        continue;
      }
      $options = [];
      foreach ($this->bundleInfo->getBundleInfo($entity_type_id) as $bundle => $info) {
        $options[$entity_type_id . ':' . $bundle] = $info['label'];
      }
      if ($options) {
        $form['tracked'][$entity_type_id] = [
          '#type' => 'checkboxes',
          '#title' => $entity_type->getLabel(),
          '#options' => $options,
          '#default_value' => array_values(array_intersect($current, array_keys($options))),
        ];
      }
    }

    $form['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Keep entries for'),
      '#field_suffix' => $this->t('days'),
      '#min' => 0,
      '#default_value' => (int) $config->get('retention_days'),
      '#description' => $this->t('Older entries are deleted on cron. 0 keeps them forever.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $tracked = [];
    foreach ((array) $form_state->getValue('tracked') as $selected) {
      $tracked = array_merge($tracked, array_values(array_filter((array) $selected)));
    }
    sort($tracked);

    $this->config('field_audit_log.settings')
      ->set('tracked', $tracked)
      ->set('retention_days', max(0, (int) $form_state->getValue('retention_days')))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
