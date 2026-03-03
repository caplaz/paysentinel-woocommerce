# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.2] - 2026-03-02

### Fixed

- **Critical**: Fixed "Cleared 0 simulated orders" issue in HPOS environments by replacing insufficient version-only database update check (`needs_update()`) with explicit table existence verification (`tables_exist()`).
- Ensured database tables are created at runtime in `init_components()` and `upsert_simulated_failure_transaction()` to handle cases where activation failed or DB version option was set during partial activation.
- Added diagnostics to surface missing transactions table as an error with self-healing on the next page load.
- Added "Transactions Table Exists" row to admin diagnostics UI for visibility into database schema health.

## [1.0.1] - 2026-02-24

### Added

- Sidebar menu item renamed to "Remote Dashboard" linking to external site.
- `SIDEBAR_HELP_URL` constant introduced to differentiate sidebar link from in-page help.
- Added spacing between gateway health cards; improved grid margins.
- Time period label removed from gateway health UI for cleaner layout.
- Styling consistency fixes for subtitle text on health page.
- Changelog file created.

### Fixed

- Build script simplified to avoid path issues in CI; `npm run build` now creates ZIP in repo root.
