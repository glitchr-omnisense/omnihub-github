# Changelog

## Unreleased

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

## 1.0.0

- A hub's packages read from GitHub releases and their `.tgz` assets.
