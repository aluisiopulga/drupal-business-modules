# Acknowledgement Notices

Internal notices that recipients **must acknowledge** before they can keep
using the site. Useful for policy changes, closing deadlines or anything a
team must not miss, with a record of **who read it and when**.

## Requirements

| | |
|---|---|
| Drupal | 10.3 or later, or 11 |
| PHP | 8.1 or later |
| Core modules | User (`user`) |
| Contrib modules | none |
| Libraries | none (the overlay is pure CSS, no JavaScript) |

## Installation

Copy the `acknowledgement_notices` folder to `web/modules/custom/` and enable it:

```bash
drush en acknowledgement_notices
```

The module creates two tables, `ack_notice` and `ack_notice_recipient`, which
are removed when the module is uninstalled.

## Usage

1. A user with *Send acknowledgement notices* opens **Notices → Send a notice**
   (`/notices/add`), writes a title and a message, and sends it to everyone or
   to selected users.
2. On their next page, each recipient sees the notice over the site and must
   click **I have read and understood** to continue. If several are waiting,
   they appear one at a time, oldest first, with "2 more waiting".
3. The notice page (`/notices/{id}`) shows the author and administrators who
   acknowledged it and when.

A "Notices" link is added to the user account menu.

## Permissions

| Permission | Allows |
|---|---|
| Send acknowledgement notices | Send notices; edit or delete your own until someone acknowledges them |
| Administer acknowledgement notices | See all notices and their acknowledgements; edit or delete any notice until someone acknowledges it |

## Design decisions

- **Recipients are fixed at send time.** "Everyone" means every active user at
  that moment: the history answers who had to read it, and people who join
  later don't inherit old notices.
- **No changes after the first acknowledgement.** Editing a notice someone
  already acknowledged would change what they declared they read.
- **Deleting hides, it doesn't erase**, so the acknowledgement history stays.
- **Rendered in the HTML, not through BigPipe.** Placeholders for logged-in
  users are filled with JavaScript; with JavaScript blocked or broken, the
  notice would never appear. The overlay is written in the page and styled with
  CSS only, so it always appears.
- Logging in, logging out and password reset pages are never blocked.
- Deleting a user account removes their pending acknowledgements.

## Tests

```bash
vendor/bin/phpunit -c web/core web/modules/custom/acknowledgement_notices
```

## License

GPL-2.0-or-later.
