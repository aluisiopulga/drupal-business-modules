# Reference Delete Guard

Prevents deleting content that **other content still references**.

Deleting a bank account that still has transactions, or a customer that still
has invoices, leaves those records pointing to nothing: reports show empty rows
and totals stop adding up. This module blocks the deletion and tells the user
exactly what still references the item ("Referenced by 3 Invoice and 1 Payment").

## Requirements

| | |
|---|---|
| Drupal | 10.3 or later, or 11 |
| PHP | 8.1 or later |
| Core modules | none beyond the always-enabled ones |
| Contrib modules | none (Paragraphs references are detected when Paragraphs is installed) |
| Libraries | none |

## Installation

Copy the `reference_delete_guard` folder to `web/modules/custom/` and enable it:

```bash
drush en reference_delete_guard
```

## Configuration

*Configuration → Content authoring → Reference delete guard*
(`/admin/config/content/reference-delete-guard`)

Tick the bundles to protect (for example *Content: Account*, *Content: Customer*,
*Taxonomy term: Cost centre*). Any content entity type can be protected.

## Permissions

| Permission | Allows |
|---|---|
| Administer reference delete guard | Choose which bundles are protected |

## How it works

- Every **configurable** entity reference field (`entity_reference` and
  `entity_reference_revisions`) of every content entity type is checked,
  including fields created later. Base fields such as the author are ignored.
- **Delete access** is forbidden while anything references the item: the
  Delete button, the `/delete` URL and bulk actions all respect it.
- The **edit form** explains why there is no Delete button.
- **Administrators** (who bypass access checks) still reach the delete form,
  but it explains the problem and has no confirm button.
- **Bulk delete** refuses the whole batch if one item is protected, so nothing
  is half-deleted.
- Access results carry the right cache tags: as soon as the last reference is
  removed, the item becomes deletable again.

Deleting from code or drush is not blocked: that is how an administrator cleans
up after reassigning the references.

## Similar modules

[Entity Reference Integrity](https://www.drupal.org/project/entity_reference_integrity)
solves a similar problem. This module focuses on a simple per-bundle setup and
on explaining to editors what is blocking the deletion.

## Tests

```bash
vendor/bin/phpunit -c web/core web/modules/custom/reference_delete_guard
```

## License

GPL-2.0-or-later.
