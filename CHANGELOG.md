# Changelog

## [Unreleased]

## [1.0.1] - 2026-11-13

### Changed
- Neutral example data in docs and tests.

## [1.0.0] - 2026-11-13

- The three months a tenant's file may be kept are declared as `rights.retention`
  (`{"maxAge": 7689600, "covers": ...}`, 89 days, the shortest three months), where they were
  `caching.maxTtl` before the schema had a retention; `caching.maxTtl` is now null (the terms bound
  how long the file is kept, not how long an answer is served again). omnisense/contracts 1.0
  reads `rights.retention`, and bounds `PolicyGuard::cacheTtl()` by it (nothing in omnisign reads it).

### Changed

- Requires `omnisense/contracts: ^1.0`, `omnisense/omnisign: ^1.1`: stable versions, so `composer require omnisign/dossierfacile` installs in a project at the default minimum-stability. `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's shared workflow) and the release on a tag (`release.yml`).

