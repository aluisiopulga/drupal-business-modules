<?php

declare(strict_types=1);

namespace Drupal\Tests\flexible_decimal\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\flexible_decimal\DecimalParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @coversDefaultClass \Drupal\flexible_decimal\DecimalParser
 * @group flexible_decimal
 */
#[CoversClass(DecimalParser::class)]
#[Group('flexible_decimal')]
class DecimalParserTest extends UnitTestCase {

  /**
   * Parses typed input into a canonical number.
   *
   * @dataProvider providerParse
   */
  #[DataProvider('providerParse')]
  public function testParse(string $input, ?string $expected, bool $allow_thousands = TRUE): void {
    $this->assertSame($expected, DecimalParser::parse($input, $allow_thousands));
  }

  /**
   * Inputs and their canonical form.
   */
  public static function providerParse(): array {
    return [
      'comma decimal' => ['1234,56', '1234.56'],
      'dot decimal' => ['1234.56', '1234.56'],
      'large value' => ['12186333,22', '12186333.22'],
      'above 3999999.99' => ['4000000,00', '4000000'],
      'brazilian thousands' => ['1.234.567,89', '1234567.89'],
      'english thousands' => ['1,234,567.89', '1234567.89'],
      'spaces as thousands' => ['1 234 567,89', '1234567.89'],
      'thousands without decimals' => ['1.234.567', '1234567'],
      'negative' => ['-0,50', '-0.5'],
      'negative zero' => ['-0', '0'],
      'plus sign' => ['+12', '12'],
      'leading dot' => ['.5', '0.5'],
      'trailing zeros' => ['10,500', '10.5'],
      'leading zeros' => ['007', '7'],
      'empty' => ['', NULL],
      'letters' => ['12a', NULL],
      'two decimal separators' => ['1,2,3.4.5', NULL],
      'bad grouping' => ['12.34.567', NULL],
      'thousands refused' => ['1.234.567,89', NULL, FALSE],
      'single separator still fine' => ['1234,5', '1234.5', FALSE],
    ];
  }

  /**
   * Checks numbers against a DECIMAL(precision, scale) column.
   */
  public function testFits(): void {
    $this->assertTrue(DecimalParser::fits('9999999999999.99', 15, 2));
    $this->assertFalse(DecimalParser::fits('10000000000000', 15, 2));
    $this->assertFalse(DecimalParser::fits('1.234', 15, 2), 'Too many decimals.');
    $this->assertTrue(DecimalParser::fits('-0.5', 3, 1));
  }

  /**
   * Compares canonical numbers without floats.
   */
  public function testCompare(): void {
    $this->assertSame(0, DecimalParser::compare('1.50', '1.5'));
    $this->assertSame(1, DecimalParser::compare('10', '9.99'));
    $this->assertSame(-1, DecimalParser::compare('-10', '-9.99'));
    $this->assertSame(-1, DecimalParser::compare('-1', '0'));
    $this->assertSame(1, DecimalParser::compare('3999999.991', '3999999.99'));
  }

  /**
   * Formats numbers with the chosen separator.
   */
  public function testFormat(): void {
    $this->assertSame('1234,56', DecimalParser::format('1234.56', ','));
    $this->assertSame('1234.56', DecimalParser::format('1234.56', '.'));
  }

}
