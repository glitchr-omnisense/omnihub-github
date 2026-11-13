# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version. It requires `omnisense/contracts` `^1.0` and
`omnisense/omnihub` `^1.0` with no stability flag, so
`composer require omnihub/github` installs it in a project with Composer's
default minimum-stability; the 1.x series follows semantic versioning. A tag
`vX.Y.Z` is released by `.github/workflows/release.yml` once the package's CI
passes on it.

- `.github/workflows/ci.yml` (glitchr-studio/ci's php-package workflow) and
  `release.yml`; the README opens on the install line and a first call that
  needs no account (a public repository, anonymously).


- On omnisense/contracts (`^1.0`) through omnihub's HTTP kit: `Api`'s
  requests and the tarballs' downloads are sent once (`Http\NoRetry`), with a
  timeout and the User-Agent `omnihub (+https://github.com/glitchr-omnisense/omnihub)`
  (it was `omnihub`); a network failure is an `UnreachableException` without
  the token.
- GitHub's limits through the kit's `Backoff` (`X-RateLimit-Reset` as a Unix
  time, `Retry-After`, the `RateLimit` fields), its waits kept in a limiter
  that can be shared (`Api`'s `$limiter`); any 429 now holds the requests (60 s
  when GitHub gives no wait), as a 403 with `Retry-After` or nothing remaining
  did.
- The usage policy of `policy.json` enforced: `extra.omnisense.policy`, its
  `anonymous` or `token` plan checked and counted by the kit's `PolicyGuard`
  (`Api::policy()`, a site's tightened one through `$policy`).
- `RateLimited` is an `Omnihub\Exception\UsageLimitReachedException` (the kit's
  `UsageLimitReached`: `retryAfter`, `limit`), `until` kept; a listing GitHub
  refuses is an `Omnihub\Exception\ProviderException` (a `RuntimeException`, as
  before), naming the path only.
- `Repository` reads its releases through `Api` (ETags, rate limit, policy),
  the one given (`$api`, `fromEnvironment()`'s fourth argument) or its own;
  `Api::fromEnvironment()` takes a limiter.
- PHPStan at level max with an empty baseline (it was level 8); `BareTest` on
  the kit's `BareTestCase`; the tests run in omnisense/omnihub's harness.

- `Omnihub\GitHub\Owner`: a `github` source (owner + pattern) resolved at
  build time - the owner's public repositories that match, with a
  `package.json` at their root, each a `git` source at its newest version tag
  (a stable one first). A private or internal repository is never read.
  `omnihub build --resolve-github` uses it.
- `Omnihub\GitHub\Api`: GitHub's REST API with the last ETag of each answer
  (a 304 reuses it; kept in a file between builds, per token mark), and
  nothing sent past `X-RateLimit-Remaining: 0` or a `Retry-After`
  (`RateLimited`).
- `policy.json`: the API's limits as the omnisense family declares them.

- A hub's packages read from GitHub releases and their `.tgz` assets.
