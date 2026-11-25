# Flexible Decimal Widget

A widget for **decimal fields** that accepts what people actually type:

| Typed | Stored |
|---|---|
| `1234,56` | `1234.56` |
| `1234.56` | `1234.56` |
| `4.000.000,00` | `4000000` |
| `1,234,567.89` | `1234567.89` |
| `1 234 567,89` | `1234567.89` |

Core's number widget renders `<input type="number">`. Browsers reject a comma
as decimal separator there, and their own `step` and `max` checks can refuse
valid amounts. In countries that write `1.234,56` this causes constant errors
when entering money. This widget uses a text input and validates on the
server, against the field's real limits.

## Requirements

| | |
|---|---|
| Drupal | 10.3 or later, or 11 |
| PHP | 8.1 or later (no `bcmath` needed) |
| Core modules | Field (`field`) |
| Contrib modules | none |
| Libraries | none |

## Installation

Copy the `flexible_decimal` folder to `web/modules/custom/` and enable it:

```bash
drush en flexible_decimal
```

## Configuration

*Structure → Content types → (type) → Manage form display*: choose
**Flexible decimal (comma or dot)** for any Decimal field.

| Setting | Default | |
|---|---|---|
| Placeholder | empty | |
| Show the current value with | comma | Both separators are always accepted when typing |
| Accept thousands separators | yes | `1.234.567,89` and `1,234,567.89` |

## Validation rules

- Must be a number. When both `.` and `,` appear, the last one is the decimal
  separator; a single `.` or `,` is always the decimal separator.
- Thousands groups must be regular (`1.234.567`, not `12.34.567`).
- Must fit the field's **precision and scale**: a `DECIMAL(15,2)` field accepts
  up to `9999999999999.99` and at most two decimals.
- Respects the field's **minimum and maximum** settings.
- Numbers are handled as exact strings, never as floats, so `12186333.22` is
  stored exactly as typed.

## Tests

Unit tests for the parser and kernel tests for the widget validation.

```bash
vendor/bin/phpunit -c web/core web/modules/custom/flexible_decimal
```

## License

GPL-2.0-or-later.
