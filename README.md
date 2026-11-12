# omnihub/github

Omnihub's GitHub provider: a hub's packages read from GitHub repositories.
Each published release (not a draft) whose assets hold a `.tgz` - what
`npm pack` makes - is a version: the tarball downloaded once, its
`package.json` read, its sha1 and sha512 taken, the release's notes as its
changelog. Unity installs the asset itself: the tarball stays on GitHub.

```php
use Omnihub\GitHub\Repository;
use Symfony\Component\HttpClient\HttpClient;

$packages = (new Repository('mystudio/tool', HttpClient::create(), getenv('GITHUB_TOKEN') ?: null))->packages();
```

In `hub.yaml` (`vendor/bin/omnihub build`): `- github: mystudio/tool`, and
optionally `asset: '/\.tgz$/'` to choose which assets count. `GITHUB_TOKEN`,
when set (a GitHub Action's own), lifts the API's limits.

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
  GitHub answers 403/429 with `Retry-After`, nothing more is sent until the
  reset (`RateLimited`): bin/omnihub then leaves the source for the clients.
- No page of github.com is read: the REST API only. Its limits are declared
  in [policy.json](policy.json).

Any HTTP client of Symfony's contracts can be given; `MockHttpClient` in the
tests, which make no network call.

## Tests

```sh
docker run --rm -v "$PWD/..":/w -w /w/github composer:2 sh -c 'composer install && vendor/bin/phpunit'
```

PHPStan: `phpstan.neon`, level 8.

MIT.
