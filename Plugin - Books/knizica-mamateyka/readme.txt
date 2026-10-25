=== Knižnica Mamateyka ===
Contributors: mikulaspolak
Tags: library, books, catalog, reservation
Requires at least: 5.9
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.93.0
License: Proprietary - All rights reserved

A small library-management plugin with a searchable, filterable public catalog, book reservations
by email, and a mobile-friendly editor interface for librarians.

Categories are managed by name. Books use category names in JSON imports and exports; internal
category database IDs are not part of the user-facing data format.
Each book's language is stored as text on the book record in JSON exports. Imports use merge/upsert
behavior: records in the file are added or updated, while local records missing from the file are
kept. Older exports with a separate `languages` field remain compatible; that field is ignored.

== Description ==

* `[kniznica_katalog]` - public book catalog with search, filters, and a reservation form. When a
  librarian is signed in, the same interface also displays book and reservation management tools.
* `[kniznica_prihlasenie]` - librarian sign-in form (uses standard WordPress authentication).
* WP Admin -> Library: settings (reservation email, text, branding, and limits), librarian account
  management, and JSON data import/export.

== Catalog filters ==

Multiple selected values within one filter are combined with OR. Different filters are combined
with AND.

Each book still stores only one language and one year. Multi-select applies only to filtering.
The catalog REST endpoint accepts comma-separated values, for example `year=2024,2023`.

== Installation ==

1. Upload the plugin ZIP via Plugins -> Add New -> Upload Plugin.
2. Activate the plugin.
3. In WP Admin -> Library -> Settings, set the email address for reservations.
4. In WP Admin -> Library -> Librarians, create accounts for staff.
5. In WP Admin -> Library -> Import/Export, import the existing book catalog (JSON).
6. Add `[kniznica_katalog]` to the library page and `[kniznica_prihlasenie]` to the sign-in page.

== Librarian accounts ==

Librarians use the frontend editor and do not have access to WordPress administration.
An administrator creates each account with one name, which is also used to sign in.
Access can be deactivated or restored in WP Admin -> Library -> Librarians.
Deactivation does not delete the WordPress account or its history; permanent account deletion is a
separate operation. Deactivating the plugin does not delete accounts or the role.
