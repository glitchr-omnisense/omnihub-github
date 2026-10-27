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

Any HTTP client of Symfony's contracts can be given; `MockHttpClient` in the
tests.

MIT.
