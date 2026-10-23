# Safe Search & Replace

A dependency-free WordPress plugin for serialized-safe search and replace across your database — the classic "I migrated domains, now half my links are broken" fix, done right.

## Why "safe"?

- **Serialized-data aware:** widget settings, theme options and meta often store PHP-serialized strings where string length matters. A naive SQL `REPLACE()` corrupts them. This plugin unserializes → replaces → re-serializes, so lengths stay correct.
- **Dry-run first:** every run shows a per-table report (matched cells, replacements, affected columns) before you commit.
- **Live runs are gated:** you must type `I UNDERSTAND` before the live button works — no accidental clicks.
- **Batched:** scans 500 rows at a time, so huge tables don't blow PHP memory.

## Scans

`posts` (content, excerpt, title, guid), `postmeta`, `options`, `comments`, `commentmeta`, `termmeta`, `usermeta`, `links`.

## Usage

1. Install and activate, then go to **Tools → Search & Replace**.
2. Enter the old string (e.g. `https://old-domain.com`) and the new one.
3. Click **Dry Run** and review the report.
4. Back up your database, type `I UNDERSTAND`, click **Run Live Replacement**.

## Requirements

WordPress 6.0+, PHP 7.4+.

## License

MIT — see [LICENSE](LICENSE).
