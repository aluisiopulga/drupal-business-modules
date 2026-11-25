# Drupal Business Modules

[![tests](https://github.com/aluisiopulga/drupal-business-modules/actions/workflows/tests.yml/badge.svg)](https://github.com/aluisiopulga/drupal-business-modules/actions/workflows/tests.yml)
![Drupal](https://img.shields.io/badge/Drupal-10.3%2B%20%7C%2011-0678be)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

Five independent Drupal modules for **business and financial applications**:
locking closed periods, protecting referenced records, auditing changes,
team notices that must be acknowledged, and money input that accepts commas.

They are clean, generic rewrites of solutions I built for a custom financial
ERP on Drupal 10 (2,000+ transactions a month, 109 bank accounts, 20 branches).
No client code, data or business rules are included: everything is configured
from the Drupal admin.

| Module | What it does | Depends on |
|---|---|---|
| [Period Lock](modules/period_lock) | Nobody creates, edits or deletes records dated in a closed accounting period. Reopening needs its own permission. | `datetime` |
| [Reference Delete Guard](modules/reference_delete_guard) | Content that other content references cannot be deleted, so no record is left pointing to nothing. | — |
| [Field Audit Log](modules/field_audit_log) | Who created, changed or deleted what, when, where, and each field's value before and after. | — |
| [Acknowledgement Notices](modules/acknowledgement_notices) | Internal notices users must acknowledge before continuing, with a record of who read them. | `user` |
| [Flexible Decimal Widget](modules/flexible_decimal) | Decimal input that accepts `1234,56`, `1.234.567,89` or `1234.56`, validated against the field precision. | `field` |

Each module works on its own. None needs contrib modules or external libraries.

## Requirements

- Drupal **10.3** or later, or Drupal **11**
- PHP **8.1** or later

## Installation

Each module is a standard Drupal module with its own `composer.json`.

**Copy the folder** you need into your site and enable it:

```bash
cp -r modules/period_lock /path/to/site/web/modules/custom/
drush en period_lock
```

**Or with Composer**, using a path repository after cloning this repository:

```bash
composer config repositories.business-modules path '/path/to/drupal-business-modules/modules/*'
composer require aluisiopulga/period_lock:^1.0
drush en period_lock
```

Configuration, permissions and design notes are in each module's README.

## Quality

- **Kernel and unit tests** for every module, run on GitHub Actions against
  Drupal 10.3, 10 and 11 with PHP 8.1 and 8.3.
- **Drupal coding standards** (Drupal and DrupalPractice) checked on every push.
- Dependency injection, configuration schemas, cache metadata on every access
  result, and no deprecated APIs.

## Running the tests locally

```bash
composer create-project drupal/recommended-project:^11 site
cd site
composer require --dev drupal/core-dev:^11
cp -r /path/to/drupal-business-modules/modules/* web/modules/custom/
cd web
SIMPLETEST_DB=sqlite://localhost/sites/simpletest/db.sqlite ../vendor/bin/phpunit -c core modules/custom
```

## License

GPL-2.0-or-later, the same license as Drupal.

## Author

**Aluisio Ricardo Marques Pulga**: Senior Software Architect, Drupal & Harvard Dataverse.
[LinkedIn](https://www.linkedin.com/in/aluisiopulga) · [Drupal.org](https://www.drupal.org/u/aluisiopulga) · [GitHub](https://github.com/aluisiopulga)
