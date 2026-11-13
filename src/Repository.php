<?php

namespace Omnihub\GitHub;

use Omnihub\Http\Downloader;
use Omnihub\Package;
use Omnihub\PackageSource;
use Omnihub\Tarball;
use Omnihub\Version;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A GitHub repository's packages: each published release (a draft is not) whose assets hold a
 * .tgz - what `npm pack` makes, every file under package/ -, that tarball downloaded once, its
 * package.json read, its sha1 and sha512 taken; the release's notes as its changelog, its date
 * as the version's. The tarball stays where GitHub keeps it: dist.tarball is the asset's address.
 *
 * The releases are read through Api (its ETags, GitHub's rate limit and the usage policy, on
 * omnisense/contracts' kit) - the one given, shared with other repositories and sources, or one
 * of its own -, the tarballs downloaded through omnihub's Downloader (sent once, a timeout,
 * omnihub's User-Agent, a Retry-After honoured).
 */
final class Repository implements PackageSource
{
    private readonly Api $api;

    /**
     * @param string      $repository owner/name
     * @param string|null $token      a token, for the API's limits and private repositories (GITHUB_TOKEN in an Action)
     * @param string      $asset      the assets taken, a regular expression on their names
     * @param Api|null    $api        GitHub's API as the build reads it (one for every repository); one of its own otherwise, with $client and $token
     */
    public function __construct(
        private readonly string $repository,
        private readonly HttpClientInterface $client,
        #[\SensitiveParameter] private readonly ?string $token = null,
        private readonly string $asset = '/\.tgz$/',
        ?Api $api = null,
    ) {
        if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repository)) {
            throw new \InvalidArgumentException(\sprintf('A repository is owner/name, not "%s".', $repository));
        }
        $this->api = $api ?? new Api($client, $token);
    }

    /**
     * As bin/omnihub makes it: the token from GITHUB_TOKEN, an entry's own "asset" pattern, the
     * build's Api when given.
     *
     * @param array<mixed> $entry
     */
    public static function fromEnvironment(string $repository, array $entry = [], ?HttpClientInterface $client = null, ?Api $api = null): self
    {
        $token = getenv('GITHUB_TOKEN') ?: null;

        return new self($repository, $client ?? HttpClient::create(), $token, \is_string($entry['asset'] ?? null) ? $entry['asset'] : '/\.tgz$/', $api);
    }

    public function packages(): iterable
    {
        $download = new Downloader($this->client, headers: array_filter([
            'Accept' => 'application/octet-stream',
            'X-GitHub-Api-Version' => '2022-11-28',
            'Authorization' => null !== $this->token && '' !== $this->token ? 'Bearer '.$this->token : null,
        ], static fn (?string $value): bool => null !== $value));
        $byName = [];
        foreach ($this->api->list('/repos/'.$this->repository.'/releases?per_page=100') as $release) {
            if (!empty($release['draft'])) {
                continue;
            }
            foreach (\is_array($release['assets'] ?? null) ? $release['assets'] : [] as $asset) {
                if (!\is_array($asset) || !\is_string($asset['name'] ?? null) || !\is_string($asset['browser_download_url'] ?? null) || !preg_match($this->asset, $asset['name'])) {
                    continue;
                }
                $url = $asset['browser_download_url'];
                $bytes = $download($url);
                $manifest = Tarball::manifest($bytes);
                if (!$manifest || !\is_string($manifest['name'] ?? null) || '' === $manifest['name']) {
                    continue;
                }
                $tag = \is_string($release['tag_name'] ?? null) ? $release['tag_name'] : '';
                $version = \is_scalar($manifest['version'] ?? null) ? (string) $manifest['version'] : ltrim($tag, 'v');
                $byName[$manifest['name']][] = Version::ofTarball(
                    $version,
                    $manifest,
                    $url,
                    $bytes,
                    \is_string($release['published_at'] ?? null) ? new \DateTimeImmutable($release['published_at']) : null,
                    \is_string($release['body'] ?? null) && '' !== $release['body'] ? $release['body'] : null,
                );
            }
        }
        foreach ($byName as $name => $versions) {
            yield new Package((string) $name, $versions, 'https://github.com/'.$this->repository, 'https://github.com/'.$this->repository);
        }
    }
}
