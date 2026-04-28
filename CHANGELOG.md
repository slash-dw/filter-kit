# Changelog

All notable changes to this package are documented in this file following [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added

- MIT license, Larastan (PHPStan level 8), Pint, CI, and `composer` scripts (`lint`, `analyse`, `test`, `ci`).

### Changed

- `slash-dw/core-kit` is resolved from the public Git repository until package distribution is finalized.
- While PHPStan target is level 8, this package is currently analyzed at **level 5** (`packages/RULES/06` temporary exception); enum `match` branches were simplified and typing fixes were applied for `FilterReflector`, `OrderClause`, and tests.
