<?php

declare(strict_types=1);

namespace Drupal\period_lock\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\period_lock\PeriodLockChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings: the closing date, and which bundles and date fields are locked.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Field types whose value starts with a YYYY-MM-DD date.
   */
  protected const DATE_FIELD_TYPES = ['datetime', 'daterange'];

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected PeriodLockChecker $checker,
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
      $container->get('entity_field.manager'),
      $container->get('period_lock.checker'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'period_lock_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['period_lock.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('period_lock.settings');

    $form['closed_through'] = [
      '#type' => 'date',
      '#title' => $this->t('Periods are closed up to and including'),
      '#default_value' => $config->get('closed_through'),
      '#description' => $this->t('Usually the last day of the last closed month. Leave empty to lock nothing. Moving this date backwards reopens periods and requires the "Reopen closed periods" permission.'),
    ];

    $form['lock_existing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also block editing and deleting existing content in closed periods'),
      '#default_value' => $config->get('lock_existing'),
      '#description' => $this->t('When unchecked, only new content is refused; existing records stay editable (useful while a period is being reviewed).'),
    ];

    $form['targets'] = [
      '#type' => 'details',
      '#title' => $this->t('Locked content and date fields'),
      '#description' => $this->t('For each bundle, select the date fields that decide its period. When several are selected, the earliest date counts.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $current = [];
    foreach ((array) $config->get('targets') as $target) {
      $current[$target['entity_type'] . ':' . $target['bundle']] = $target['fields'];
    }

    $found = FALSE;
    foreach ($this->dateFieldsByBundle() as $key => $info) {
      $found = TRUE;
      $form['targets'][$key] = [
        '#type' => 'checkboxes',
        '#title' => $info['label'],
        '#options' => $info['fields'],
        '#default_value' => $current[$key] ?? [],
      ];
    }

    if (!$found) {
      $form['targets']['empty'] = [
        '#markup' => '<p>' . $this->t('No content has a date field yet. Add a Date or Date range field to a content type first.') . '</p>',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $new_date = $form_state->getValue('closed_through') ?: NULL;
    if (!$this->checker->canSetClosedThrough($this->currentUser(), $new_date)) {
      $form_state->setErrorByName('closed_through', $this->t('Moving the closing date backwards reopens closed periods. You need the "Reopen closed periods" permission.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $targets = [];
    foreach ((array) $form_state->getValue('targets') as $key => $fields) {
      $fields = array_values(array_filter((array) $fields));
      if ($fields && str_contains((string) $key, ':')) {
        [$entity_type, $bundle] = explode(':', (string) $key, 2);
        $targets[] = ['entity_type' => $entity_type, 'bundle' => $bundle, 'fields' => $fields];
      }
    }

    $this->config('period_lock.settings')
      ->set('closed_through', (string) $form_state->getValue('closed_through'))
      ->set('lock_existing', (bool) $form_state->getValue('lock_existing'))
      ->set('targets', $targets)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Every bundle that has at least one date field.
   *
   * @return array<string, array{label: string, fields: array<string, string>}>
   *   Keyed by "entity_type:bundle".
   */
  protected function dateFieldsByBundle(): array {
    $result = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if (!$entity_type->entityClassImplements(FieldableEntityInterface::class)) {
        continue;
      }
      foreach ($this->bundleInfo->getBundleInfo($entity_type_id) as $bundle => $bundle_info) {
        $fields = [];
        foreach ($this->entityFieldManager->getFieldDefinitions($entity_type_id, (string) $bundle) as $name => $definition) {
          if (in_array($definition->getType(), self::DATE_FIELD_TYPES, TRUE)) {
            $fields[$name] = $definition->getLabel() . ' (' . $name . ')';
          }
        }
        if ($fields) {
          $result[$entity_type_id . ':' . $bundle] = [
            'label' => $entity_type->getLabel() . ': ' . $bundle_info['label'],
            'fields' => $fields,
          ];
        }
      }
    }

    return $result;
  }

}
