# Repository Guidelines

## Project Structure & Module Organization

- `monday-resources-plugin.php` boots the plugin and wires core classes.
- `includes/` holds PHP classes for admin pages, API sync, shortcode rendering, exports, and migrations.
- `assets/` contains frontend JS/CSS used by modals and search/filter UI.
- `templates/` includes PHP templates for modal forms.
- `vendor/` is Composer-managed dependencies (export tooling, PDFs, spreadsheets).
- `wp-google-maps/` and `wp-google-maps-pro/` are bundled dependencies; avoid editing unless required.

## Build, Test, and Development Commands

This plugin is vanilla PHP/JS and does not require a build step.

- `composer install` installs PHP dependencies if `vendor/` is missing or needs refresh.
- `composer dump-autoload` regenerates the autoloader after adding classes under `includes/`.

Manual verification happens in WordPress:
- Admin > Monday Resources > Settings > “Sync Now” to test Monday.com sync.
- Use the `[monday_resources]` shortcode on a page to verify frontend search and filters.

## Coding Style & Naming Conventions

- PHP follows WordPress-style conventions (4-space indent, braces on same line).
- JavaScript uses 4-space indent and plain functions (no build tooling).
- Class files live in `includes/` and use `class-*.php` naming (e.g., `class-monday-admin.php`).
- Keep user-facing copy trauma-informed and accessible (large text, clear labels).

## Testing Guidelines

There is no automated test suite in this repository.
- Validate changes manually in the WordPress admin and frontend.
- If you introduce new admin UI or AJAX handlers, verify nonce checks and error states.

## Commit & Pull Request Guidelines

Recent history shows a mix of descriptive sentences and occasional conventional-style commits
(e.g., `feat(hours): ...`). Match the existing pattern: short, scoped, and user-facing.

For PRs:
- Describe behavior changes and link any relevant issues.
- Include screenshots or screen recordings for UI changes.
- Note any manual verification steps (e.g., “Synced board, checked filters, submitted report”).

## Security & Configuration Tips

- Monday.com API token and board ID are configured in WordPress admin, not in code.
- AJAX endpoints must use WordPress nonces and sanitize all input.
