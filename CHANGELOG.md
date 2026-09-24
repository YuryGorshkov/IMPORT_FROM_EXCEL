# Changelog

## 0.6.0 - 2026-09-24

- Reworked nested sections to match the Bitrix data model instead of treating a level as a single synthetic field.
- Added a separate destination group for each section level with ID, name, code, XML_ID, activity, sorting, description and picture fields.
- Added hierarchical lookup by ID, XML_ID, code or name under the exact parent section.
- Added creation and updating of real `CIBlockSection` records and assignment of elements to the deepest level.
- Added rollback snapshots for existing sections changed during import.
- Kept existing profiles compatible by upgrading legacy `SECTION:N` mappings to `SECTION:N:NAME` automatically.

## 0.5.2 - 2026-09-24

- Replaced free-form element field codes with a guided list of supported Bitrix fields.
- Displayed the immutable Bitrix field code beside each friendly field name.
- Added preview/detail text, preview/detail picture, activity, dates, tags, sorting and section fields.
- Added protected image import from public HTTP/HTTPS URLs, `/upload` paths and existing Bitrix file IDs.
- Rejected unsupported element field codes during mapping validation.
- Added grouped destination selectors modeled after the `kda.importexcel` field picker.
- Recognized numbered section columns as nested IBlock section paths instead of properties.
- Added find-or-create behavior for nested sections and safe cleanup of empty imported sections during rollback.
- Automatically upgraded legacy `RAZDEL_N_GO_UROVNYA` property mappings and invalid legacy element fields.

## 0.5.1 - 2026-09-24

- Added profile-level element code generation from the item name or unique field.
- Added collision-safe suffixes while preserving existing non-empty element codes.
- Added prominent Bitrix notifications after profile save, IBlock creation and completed import.

## 0.5.0 - 2026-09-24

- Added inline IBlock creation while configuring an import profile.
- Separated the structural sample from the current working file selected for each run.
- Added working-file header validation against the saved profile before processing.
- Removed the redundant worksheet field from the run page and added automatic sheet selection.
- Replaced the dry-run checkbox with a guided check-then-confirm workflow that reuses the same verified file.
- Added an optional direct transition from profile setup to checking the uploaded sample.

## 0.4.0 - 2026-09-24

- Added a safe spreadsheet preview before column discovery.
- Added visual header-row selection with row numbers and highlighting.
- Added automatic header-row suggestion for files with title and service rows.
- Added worksheet selection based on the actual workbook sheet list.
- Added configurable preview start row for files with long preambles.
- Kept preview uploads in isolated protected temporary storage with opaque tokens and expiration.

## 0.3.0 - 2026-09-23

- Redesigned all Bitrix administration pages with a clear card-based layout.
- Replaced the raw mapping JSON editor with a guided column mapping table.
- Added descriptive tooltips for profile, mapping, import and history settings.
- Replaced the target IBlock numeric input with a named catalog selector.
- Added localized status badges, safer dry-run guidance and import result cards.
- Made profile save redirects work behind reverse proxies and forwarded ports.
- Updated the Russian documentation to match the new workflow.

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
