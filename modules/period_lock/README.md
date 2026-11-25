# Period Lock

Locks content dated in **closed accounting periods**. Once a month is closed,
nobody can create, edit or delete records dated in it, so the figures that
were reported stay the figures in the system.

## Requirements

| | |
|---|---|
| Drupal | 10.3 or later, or 11 |
| PHP | 8.1 or later |
| Core modules | Datetime (`datetime`) |
| Contrib modules | none |
| Libraries | none |

## Installation

Copy the `period_lock` folder to `web/modules/custom/` and enable it:

```bash
drush en period_lock
```

## Configuration

*Configuration → Content authoring → Period lock* (`/admin/config/content/period-lock`)

1. For each content type, tick the **date fields** that decide which period a
   record belongs to. Date and Date range fields are supported. When several
   are ticked, the **earliest** date counts, so a record cannot escape the lock
   because one of its dates is still open.
2. Set **Periods are closed up to and including**, usually the last day of the
   last closed month.
3. Optionally untick **Also block editing and deleting existing content** while
   a period is under review: new records are refused, existing ones stay editable.

## Permissions

| Permission | Allows |
|---|---|
| Administer period lock | Change the settings and move the closing date **forward** |
| Reopen closed periods | Move the closing date **backwards** (reopening periods) |
| Bypass period lock | Create, edit and delete content in closed periods |

Users with *Bypass period lock* see a warning on the edit form of closed content.

## How it works

Two layers, so the lock does not depend on which screen is used:

- **Access** (`hook_entity_access`): `update` and `delete` are forbidden for
  existing content in a closed period. Edit and Delete buttons disappear,
  direct URLs return 403, and bulk actions refuse the item.
- **Validation** (the `PeriodLock` entity constraint): new content dated in a
  closed period, edits to locked content, and dates moved into a closed period
  are refused wherever entities are validated: forms, REST and JSON:API.

Code that saves entities without validating them (custom scripts, `drush php`)
is not checked, on purpose: that is how administrators fix data.

## Tests

Kernel tests in `tests/src/Kernel` cover access, validation, multiple date
fields, the reopen permission and unconfigured bundles.

```bash
vendor/bin/phpunit -c web/core web/modules/custom/period_lock
```

## License

GPL-2.0-or-later.
