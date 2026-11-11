# Changelog

## [Unreleased]

- The three months a tenant's file may be kept are declared as `rights.retention`
  (`{"maxAge": 7689600, "covers": ...}`, 89 days, the shortest three months), where they were
  `caching.maxTtl` before the schema had a retention; `caching.maxTtl` is now null (the terms bound
  how long the file is kept, not how long an answer is served again). omnisense/contracts reads
  `rights.retention` since 16c8da1, so the policy loads with 1568f2c, what the sites' vendors hold;
  `PolicyGuard::cacheTtl()` is bounded by the retention from **9a96e22** on (with an older kernel it
  no longer gives the 89 days: nothing in omnisign reads it).
