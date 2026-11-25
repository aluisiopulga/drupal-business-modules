<?php

declare(strict_types=1);

namespace Drupal\flexible_decimal;

/**
 * Parses and checks decimal numbers typed by people, as exact strings.
 *
 * Works on strings from start to end, never on floats: 12186333.22 must be
 * stored as typed, not as 12186333.219999999. No bcmath extension needed.
 */
final class DecimalParser {

  /**
   * Turns user input into a canonical decimal string ("-1234.56").
   *
   * Accepted: "1234,56", "1234.56", "-0,5", and with thousands separators
   * (when allowed) "1.234.567,89", "1,234,567.89", "1 234 567,89".
   * When both "." and "," appear, the last one is the decimal separator.
   * A single "," or a single "." is always the decimal separator.
   *
   * @return string|null
   *   The canonical number, or NULL when the input is not a number.
   */
  public static function parse(string $input, bool $allow_thousands = TRUE): ?string {
    $value = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', trim($input)) ?? '';
    if ($value === '') {
      return NULL;
    }

    $sign = '';
    if ($value[0] === '-' || $value[0] === '+') {
      $sign = $value[0] === '-' ? '-' : '';
      $value = substr($value, 1);
    }

    $last_dot = strrpos($value, '.');
    $last_comma = strrpos($value, ',');

    if ($last_dot !== FALSE && $last_comma !== FALSE) {
      $decimal = $last_dot > $last_comma ? '.' : ',';
      $thousands = $decimal === '.' ? ',' : '.';
      if (!$allow_thousands || !self::validGrouping(strstr($value, $decimal, TRUE), $thousands) || substr_count($value, $decimal) > 1) {
        return NULL;
      }
      $value = str_replace([$thousands, $decimal], ['', '.'], $value);
    }
    elseif ($last_dot !== FALSE || $last_comma !== FALSE) {
      $separator = $last_dot !== FALSE ? '.' : ',';
      if (substr_count($value, $separator) > 1) {
        // "1.234.567" or "1,234,567": only valid as thousands grouping.
        if (!$allow_thousands || !self::validGrouping($value, $separator)) {
          return NULL;
        }
        $value = str_replace($separator, '', $value);
      }
      else {
        $value = str_replace(',', '.', $value);
      }
    }

    if (!preg_match('/^(\d+)(?:\.(\d+))?$|^\.(\d+)$/', $value, $match)) {
      return NULL;
    }

    $integer = ltrim($match[1] ?? '', '0');
    $fraction = rtrim(($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''), '0');
    $integer = $integer === '' ? '0' : $integer;
    $canonical = $integer . ($fraction !== '' ? '.' . $fraction : '');

    return ($canonical === '0' ? '' : $sign) . $canonical;
  }

  /**
   * Whether the number fits a DECIMAL(precision, scale) column.
   */
  public static function fits(string $number, int $precision, int $scale): bool {
    [$integer, $fraction] = self::split($number);

    return strlen($fraction) <= $scale && strlen(ltrim($integer, '0')) <= $precision - $scale;
  }

  /**
   * Compares two canonical numbers like strcmp: -1, 0 or 1.
   */
  public static function compare(string $a, string $b): int {
    $negative_a = str_starts_with($a, '-');
    $negative_b = str_starts_with($b, '-');
    if ($negative_a !== $negative_b) {
      return $negative_a ? -1 : 1;
    }

    [$int_a, $frac_a] = self::split(ltrim($a, '-'));
    [$int_b, $frac_b] = self::split(ltrim($b, '-'));
    $int_a = ltrim($int_a, '0');
    $int_b = ltrim($int_b, '0');
    $length = max(strlen($frac_a), strlen($frac_b));
    $result = strlen($int_a) <=> strlen($int_b)
      ?: strcmp($int_a, $int_b) <=> 0
      ?: strcmp(str_pad($frac_a, $length, '0'), str_pad($frac_b, $length, '0')) <=> 0;

    return $negative_a ? -$result : $result;
  }

  /**
   * Formats a canonical number for display with the chosen decimal separator.
   */
  public static function format(string $number, string $decimal_separator = '.'): string {
    return $decimal_separator === ',' ? str_replace('.', ',', $number) : $number;
  }

  /**
   * Integer and fraction digits of a number without sign.
   *
   * @return array{0: string, 1: string}
   *   The integer digits and the fraction digits.
   */
  private static function split(string $number): array {
    $parts = explode('.', ltrim($number, '-+'), 2);

    return [$parts[0], $parts[1] ?? ''];
  }

  /**
   * Whether "1.234.567" grouping is regular: 1-3 digits, then groups of 3.
   */
  private static function validGrouping(string $integer_part, string $separator): bool {
    if (!str_contains($integer_part, $separator)) {
      return ctype_digit($integer_part);
    }

    return (bool) preg_match('/^\d{1,3}(' . preg_quote($separator, '/') . '\d{3})+$/', $integer_part);
  }

}
