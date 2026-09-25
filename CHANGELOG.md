# Changelog

## 0.10.0 - 2026-09-25

- Added a dedicated Bitrix-style settings window for every newly created IBlock property.
- Prefilled the property name from the Excel header and its editable symbolic code from automatic transliteration.
- Added the standard property type, activity, sorting, multiplicity, required, search, filter, hint, input-size, smart-filter and display settings.
- Added label-to-enum resolution for list properties; values of a newly created list property are added from imported cell text when needed.
- Kept existing properties and built-in element or section fields free of irrelevant generated codes in the mapping table.
- Passed new-property settings to `CIBlockProperty::Add`, including list/detail page feature flags and section property settings.

## 0.9.0 - 2026-09-25

- Split the mapping destination and the transliterated Excel header into separate columns.
- Added selectable existing iblock properties with their real names, codes, types and multiplicity.
- Kept new-property creation as an explicit choice so existing properties are updated without duplicate creation.
- Grouped secondary element fields separately and added clear type labels for numbers, dates, booleans and text formats.
- Added strict value normalization for numeric properties, sorting, activity flags, date/time fields and `text/html` modes.

## 0.8.2 - 2026-09-25

- Simplified mapping labels for content managers: only friendly destinations and transliterated source-column codes are displayed.
- Made the visible Latin code depend on the Excel header rather than the selected Bitrix destination.
- Removed PHP variables and API-specific prefixes from the profile form while preserving internal mappings unchanged.
- Displayed iblock identifiers as `ID 3` instead of the less explicit `#3`.

## 0.8.1 - 2026-09-25

- Replaced internal mapping identifiers such as `PROPERTY:ARTIKUL` with the documented Bitrix PHP array notation in the administration UI.
- Distinguished write targets (`$arFields["PROPERTY_VALUES"]["ARTIKUL"]`) from lookup filters (`$arFilter["PROPERTY_ARTIKUL"]`).
- Kept the stored profile format unchanged for full backward compatibility.

## 0.8.0 - 2026-09-25

- Replaced the crowded mapping controls with one grouped destination selector per Excel column.
- Displayed fixed Bitrix element and section keys inside selector labels instead of editable-looking fields.
- Kept property codes automatic and collision-safe, with separate choices for ordinary, multiple, file and gallery properties.
- Confirmed safe partial updates: element fields use `CIBlockElement::Update`, properties use `SetPropertyValuesEx`, and section depth is derived from `IBLOCK_SECTION_ID` by Bitrix.

## 0.7.1 - 2026-09-25

- Added automatic file-property suggestions for headers such as «Фото», «Картинка», «Изображение» and galleries.
- Added a warning when an existing IBlock uses URL templates without `#SECTION_CODE_PATH#`; existing site routes are never rewritten automatically.

## 0.7.0 - 2026-09-25

- Added a visible property type selector for ordinary values and image/file properties.
- Added protected download of public HTTP/HTTPS images into single and multiple Bitrix file properties.
- Added support for external Excel hyperlinks in image columns, even when the cell displays a label instead of the URL.
- Added newline-separated image lists for multiple properties, with one URL per line.
- Added validation when an image mapping points to an existing non-file property.
- Changed new IBlock URL templates to use `#SECTION_CODE_PATH#` so nested section codes form the complete path.
- Hid internal mapping prefixes from the visible Bitrix field-code badge to avoid confusing them with URL parts.

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
