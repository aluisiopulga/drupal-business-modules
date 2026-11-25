<?php

declare(strict_types=1);

namespace Drupal\Tests\flexible_decimal\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\flexible_decimal\Plugin\Field\FieldWidget\FlexibleDecimalWidget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the widget plugin and its server-side validation.
 *
 * @group flexible_decimal
 */
#[Group('flexible_decimal')]
#[RunTestsInSeparateProcesses]
class FlexibleDecimalWidgetTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'field', 'flexible_decimal'];

  /**
   * The widget is available for decimal fields.
   */
  public function testPluginDiscovery(): void {
    $definition = $this->container->get('plugin.manager.field.widget')->getDefinition('flexible_decimal');
    $this->assertSame(['decimal'], $definition['field_types']);
  }

  /**
   * Valid input is stored canonically; invalid input is refused.
   *
   * @dataProvider providerValidation
   */
  #[DataProvider('providerValidation')]
  public function testValidation(string $input, ?string $stored, bool $valid): void {
    $element = [
      '#value' => $input,
      '#title' => 'Amount',
      '#parents' => ['amount'],
      '#flexible_decimal' => [
        'precision' => 15,
        'scale' => 2,
        'min' => '0',
        'max' => '',
        'allow_thousands' => TRUE,
      ],
    ];
    $form_state = new FormState();
    FlexibleDecimalWidget::validateNumber($element, $form_state);

    $this->assertSame(!$valid, $form_state->hasAnyErrors());
    if ($valid) {
      $this->assertSame($stored, $form_state->getValue('amount'));
    }
  }

  /**
   * Inputs, stored values and whether they pass.
   */
  public static function providerValidation(): array {
    return [
      'comma' => ['4.000.000,00', '4000000', TRUE],
      'precision limit' => ['9999999999999,99', '9999999999999.99', TRUE],
      'over precision' => ['10000000000000', NULL, FALSE],
      'too many decimals' => ['1,234', NULL, FALSE],
      'below min' => ['-1', NULL, FALSE],
      'not a number' => ['abc', NULL, FALSE],
      'empty' => ['', NULL, TRUE],
    ];
  }

}
