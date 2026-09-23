# Changelog

## 0.2.2 - 2026-09-23

- Made repeated module installation idempotent.
- Made CLI source validation failures return a clear message and exit code 5.

## 0.2.1 - 2026-09-21

- Pinned Composer's build platform to PHP 8.1 for portable release archives.

## 0.2.0 - 2026-09-21

- Added a single-file web installer for Bitrix administrators.
- Added self-contained release archives with production dependencies.
- Added SHA-256 verification and ZIP path validation to the installer.
- Added an automated GitHub release workflow for version tags.

## 0.1.0 - 2026-09-21

- Initial clean-room implementation.
- Profiles stored through Bitrix D7 ORM.
- XLSX, XLS, ODS and CSV readers based on maintained PhpSpreadsheet.
- Chunked, resumable import jobs with dry-run mode.
- Safe declarative transformations without `eval`.
- IBlock fields and properties with add/update/upsert modes.
- Per-row diagnostics, change journal and rollback service.
- Administrative profile and import pages plus a CLI runner.
