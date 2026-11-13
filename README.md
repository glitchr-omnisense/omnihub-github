# omnihub/github

Omnihub's GitHub provider: a hub's packages read from GitHub repositories.
Each published release (not a draft) whose assets hold a `.tgz` - what
`npm pack` makes - is a version: the tarball downloaded once, its
`package.json` read, its sha1 and sha512 taken, the release's notes as its
changelog. Unity installs the asset itself: the tarball stays on GitHub.

## Installation and a first call

```sh
composer require omnihub/github
```

It requires omnisense/omnihub and symfony/http-client. No account is needed
for public repositories: anonymous calls are allowed (60 requests an hour by
GitHub's rules); a token (`GITHUB_TOKEN`, a GitHub Action's own) raises the
limit. Only GitHub's REST API is read, never a page of github.com.

```php
require __DIR__.'/vendor/autoload.php';

use Omnihub\GitHub\Repository;
use Omnihub\Upm\Registry;
use Symfony\Component\HttpClient\HttpClient;

$packages = (new Repository('mystudio/tool', HttpClient::create(), getenv('GITHUB_TOKEN') ?: null))->packages();
foreach ($packages as $package) {
    echo Registry::json(Registry::document($package)), "\n";   // what Unity reads at GET /<name>
}
```

In `hub.yaml` (`vendor/bin/omnihub build`): `- github: mystudio/tool`, and
optionally `asset: '/\.tgz$/'` to choose which assets count. `GITHUB_TOKEN`,
when set (a GitHub Action's own), lifts the API's limits. `bin/omnihub` reads
every repository and source of a build through one `Api` (the fifth argument
of `Repository`), so the rate limit, the usage policy and the kept ETags count
across them.

## A GitHub owner, resolved at build time

A `github` source of hub.yaml - an owner and a pattern of repository names -
is written into `hub.json` as it is, for the hub clients to resolve. Asked to
(`omnihub build hub.yaml _site --resolve-github`), this package resolves it
at build time instead:

```php
use Omnihub\GitHub\Api;
use Omnihub\GitHub\Owner;
use Omnihub\Source;

$api = Api::fromEnvironment('.omnihub/github.json');   // GITHUB_TOKEN when set; the ETags kept in that file
$sources = (new Owner($api))->resolve(Source::github('acme', 'unity-*'));
$api->save();
```

- Only **public** repositories: the owner's list (`/users/{owner}/repos`),
  then, for each name that matches, `package.json` at the root (raw) and the
  tags. A repository without a `package.json` naming a package is skipped.
  Each one found is a `git` source at its newest version tag (a stable one
  first; none: the default branch), named by its `displayName`, with the
  source's `install`.
- Every request carries the last `ETag` of its answer (`If-None-Match`); a
  `304` reuses the kept answer. When `X-RateLimit-Remaining` reaches 0, or
  GitHub answers 403 with `Retry-After` (a secondary limit) or nothing
  remaining, or answers 429, nothing more is sent until the reset or the wait
  (`RateLimited`): bin/omnihub then leaves the source for the clients.
- No page of github.com is read: the REST API only.

## On omnisense/contracts' kit

`Api` and the downloads go through omnihub's HTTP client, on the kit of
[omnisense/contracts](https://github.com/glitchr-omnisense/contracts):

- **sent once**: the HTTP client given is kept from retrying (`Http\NoRetry`):
  a 429 reaches the `Backoff`, a refusal is never replayed;
- **a timeout** (30 s without a byte for the API, 60 s for a tarball), the
  User-Agent `omnihub (+https://github.com/glitchr-omnisense/omnihub)` and
  `X-GitHub-Api-Version: 2022-11-28`;
- **GitHub's limits** through the kit's `Backoff`: `X-RateLimit-Reset` read as
  a Unix time, `Retry-After`, the `RateLimit` fields; the waits are kept in a
  limiter - `Api`'s own, or one given (`new Api($http, $token, limiter:
  $shared)`) that several processes share;
- **the usage policy** of [policy.json](policy.json) (declared in
  `composer.json`'s `extra.omnisense.policy`): its `anonymous` plan (60
  requests an hour) or, with a token, its `token` plan (5,000), checked before
  each request and counted after it by the kit's `PolicyGuard`. A site may
  tighten it and never loosen it:

  ```php
  $policy = Api::policy(true)->tighten(['rateLimits' => [['max' => 1000, 'window' => 'hour', 'operations' => []]]]);
  $api = new Api(HttpClient::create(), getenv('GITHUB_TOKEN') ?: null, '.omnihub/github.json', policy: $policy);
  ```
- **errors without the token**: a network failure is an
  `Omnihub\Exception\UnreachableException`, a listing GitHub refuses an
  `Omnihub\Exception\ProviderException` naming the path only. `RateLimited` is
  an `Omnihub\Exception\UsageLimitReachedException`, so the kit's
  `UsageLimitReached` too (`retryAfter`), and keeps its `until`.

Any HTTP client of Symfony's contracts can be given; `MockHttpClient` in the
tests, which make no network call.

## Tests

In omnisense/omnihub's harness, with this checkout beside the core
(`OMNIHUB_PLUGINS=../..` in its `docker/.env`):

```sh
cd ../core/docker
docker compose run --rm omnihub test      # this package's tests among the family's
docker compose run --rm omnihub phpstan   # PHPStan at level max, an empty baseline
docker compose run --rm omnihub bare      # a hub built in plain PHP from recorded GitHub answers
```

MIT.
