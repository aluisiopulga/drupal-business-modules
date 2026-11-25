# Field Audit Log

Records **who** created, changed or deleted content, **when**, on **which page**,
and the value of **each changed field before and after**.

Revisions store the whole entity on every save, say nothing about deletions and
are often disabled for performance. This module answers the questions people
actually ask during an audit: who changed this balance, from what to what, and
who deleted that record?

## Requirements

| | |
|---|---|
| Drupal | 10.3 or later, or 11 |
| PHP | 8.1 or later |
| Core modules | none beyond the always-enabled ones |
| Contrib modules | none |
| Libraries | none |

## Installation

Copy the `field_audit_log` folder to `web/modules/custom/` and enable it:

```bash
drush en field_audit_log
```

The module creates one database table, `field_audit_log`, which is removed when
the module is uninstalled.

## Configuration

*Configuration → System → Field audit log* (`/admin/config/system/field-audit-log`)

- Tick the bundles to track. Any content entity type works.
- Optionally set a retention period; older entries are deleted on cron.

## Viewing the log

*Reports → Field audit log* (`/admin/reports/field-audit-log`)

Each row shows the date, the user, the action, the item, the page where it
happened (or `cli` for drush and cron) and the changed fields as
`before → after`. Click a user or an item to see only their entries
(`?uid=3`, `?entity_type=node&entity_id=12`).

## Permissions

| Permission | Allows |
|---|---|
| View field audit log | See the log, including old values |
| Administer field audit log | Choose tracked bundles and retention |

## How it works

- `hook_entity_insert`, `hook_entity_update` and `hook_entity_predelete` record
  every save made through the Entity API: forms, bulk actions, REST, migrations
  and drush. Direct SQL writes are not recorded.
- Saves that change nothing are ignored (Drupal sometimes saves an entity twice
  in one request).
- Bookkeeping fields (`changed`, revision fields) and computed fields are skipped.
- Entries have no foreign key: they outlive the deleted entity.
- Compatible with Drupal 11.2+ `getOriginal()` and the older `->original`.

## Tests

```bash
vendor/bin/phpunit -c web/core web/modules/custom/field_audit_log
```

## License

GPL-2.0-or-later.
