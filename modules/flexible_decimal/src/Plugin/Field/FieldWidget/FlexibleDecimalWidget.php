<?php

declare(strict_types=1);

namespace Drupal\flexible_decimal\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flexible_decimal\DecimalParser;

/**
 * Text input for decimals that accepts comma or dot as decimal separator.
 *
 * Core's number widget renders <input type="number">, whose browser
 * validation rejects "1234,56" and may impose its own step and max. This
 * widget uses a plain text input and validates on the server, against the
 * field's real limits: its precision and scale, and its min and max settings.
 */
#[FieldWidget(
  id: 'flexible_decimal',
  label: new TranslatableMarkup('Flexible decimal (comma or dot)'),
  field_types: ['decimal'],
)]
class FlexibleDecimalWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return [
      'placeholder' => '',
      'decimal_separator' => ',',
      'allow_thousands_separator' => TRUE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $element['placeholder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Placeholder'),
      '#default_value' => $this->getSetting('placeholder'),
    ];
    $element['decimal_separator'] = [
      '#type' => 'select',
      '#title' => $this->t('Show the current value with'),
      '#options' => [',' => $this->t('Comma (1234,56)'), '.' => $this->t('Dot (1234.56)')],
      '#default_value' => $this->getSetting('decimal_separator'),
      '#description' => $this->t('Both separators are always accepted when typing.'),
    ];
    $element['allow_thousands_separator'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Accept thousands separators (1.234.567,89)'),
      '#default_value' => $this->getSetting('allow_thousands_separator'),
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    return [
      $this->t('Shows values with @separator', ['@separator' => $this->getSetting('decimal_separator') === ',' ? $this->t('comma') : $this->t('dot')]),
      $this->getSetting('allow_thousands_separator') ? $this->t('Thousands separators accepted') : $this->t('No thousands separators'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $value = $items[$delta]->value ?? NULL;
    $canonical = $value === NULL || $value === '' ? NULL : DecimalParser::parse((string) $value, FALSE);
    $settings = $this->getFieldSettings();

    $element['value'] = $element + [
      '#type' => 'textfield',
      '#default_value' => $canonical === NULL ? '' : DecimalParser::format($canonical, $this->getSetting('decimal_separator')),
      '#placeholder' => $this->getSetting('placeholder'),
      '#size' => 20,
      '#maxlength' => 64,
      '#attributes' => ['inputmode' => 'decimal'],
      '#element_validate' => [[static::class, 'validateNumber']],
      '#flexible_decimal' => [
        'precision' => (int) ($settings['precision'] ?? 10),
        'scale' => (int) ($settings['scale'] ?? 2),
        'min' => $settings['min'] ?? '',
        'max' => $settings['max'] ?? '',
        'allow_thousands' => (bool) $this->getSetting('allow_thousands_separator'),
      ],
      '#field_prefix' => $settings['prefix'] ?? '',
      '#field_suffix' => $settings['suffix'] ?? '',
    ];

    return $element;
  }

  /**
   * Validates the typed number and stores it in canonical form.
   */
  public static function validateNumber(array &$element, FormStateInterface $form_state): void {
    $input = trim((string) $element['#value']);
    if ($input === '') {
      $form_state->setValueForElement($element, NULL);
      return;
    }

    $rules = $element['#flexible_decimal'];
    $title = $element['#title'] ?? t('Value');
    $number = DecimalParser::parse($input, $rules['allow_thousands']);

    if ($number === NULL) {
      $form_state->setError($element, t('%field must be a number, for example 1234,56 or 1234.56.', ['%field' => $title]));
      return;
    }
    if (!DecimalParser::fits($number, $rules['precision'], $rules['scale'])) {
      $form_state->setError($element, t('%field accepts at most @int digits before the decimal separator and @scale after it.', [
        '%field' => $title,
        '@int' => $rules['precision'] - $rules['scale'],
        '@scale' => $rules['scale'],
      ]));
      return;
    }
    foreach (['min' => -1, 'max' => 1] as $limit => $direction) {
      $bound = DecimalParser::parse((string) $rules[$limit], FALSE);
      if ($bound !== NULL && DecimalParser::compare($number, $bound) === $direction) {
        $form_state->setError($element, $limit === 'min'
          ? t('%field must be at least %bound.', ['%field' => $title, '%bound' => $bound])
          : t('%field must be at most %bound.', ['%field' => $title, '%bound' => $bound]));
        return;
      }
    }

    $form_state->setValueForElement($element, $number);
  }

}
