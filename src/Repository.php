<?php

namespace Omnihub\GitHub;

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
 */
final class Repository implements PackageSource
{
    private const API = 'https://api.github.com';

    /**
     * @param string      $repository owner/name
     * @param string|null $token      a token, for the API's limits and private repositories (GITHUB_TOKEN in an Action)
     * @param string      $asset      the assets taken, a regular expression on their names
     */
    public function __construct(
        private readonly string $repository,
        private readonly HttpClientInterface $client,
        private readonly ?string $token = null,
        private readonly string $asset = '/\.tgz$/',
    ) {
        if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repository)) {
            throw new \InvalidArgumentException(\sprintf('A repository is owner/name, not "%s".', $repository));
        }
    }

    /** As bin/omnihub makes it: the token from GITHUB_TOKEN, an entry's own "asset" pattern. */
    public static function fromEnvironment(string $repository, array $entry = [], ?HttpClientInterface $client = null): self
    {
        $token = getenv('GITHUB_TOKEN') ?: null;

        return new self($repository, $client ?? HttpClient::create(), $token, (string) ($entry['asset'] ?? '/\.tgz$/'));
    }

    public function packages(): iterable
    {
        $byName = [];
        foreach ($this->releases() as $release) {
            if (!empty($release['draft'])) {
                continue;
            }
            foreach ($release['assets'] ?? [] as $asset) {
                if (!preg_match($this->asset, (string) ($asset['name'] ?? ''))) {
                    continue;
                }
                $url = (string) $asset['browser_download_url'];
                $bytes = $this->client->request('GET', $url, ['headers' => $this->headers('application/octet-stream')])->getContent();
                $manifest = Tarball::manifest($bytes);
                if (!$manifest || empty($manifest['name'])) {
                    continue;
                }
                $version = (string) ($manifest['version'] ?? ltrim((string) $release['tag_name'], 'v'));
                $byName[$manifest['name']][] = Version::ofTarball(
                    $version,
                    $manifest,
                    $url,
                    $bytes,
                    isset($release['published_at']) ? new \DateTimeImmutable((string) $release['published_at']) : null,
                    ($release['body'] ?? '') !== '' ? (string) $release['body'] : null,
                );
            }
        }
        foreach ($byName as $name => $versions) {
            yield new Package($name, $versions, 'https://github.com/'.$this->repository, 'https://github.com/'.$this->repository);
        }
    }

    /** @return iterable<array<string, mixed>> every release, page after page */
    private function releases(): iterable
    {
        $url = self::API.'/repos/'.$this->repository.'/releases?per_page=100';
        while (null !== $url) {
            $response = $this->client->request('GET', $url, ['headers' => $this->headers('application/vnd.github+json')]);
            yield from $response->toArray();
            $url = null;
            foreach ($response->getHeaders()['link'] ?? [] as $link) {
                if (preg_match('/<([^>]+)>;\s*rel="next"/', $link, $match)) {
                    $url = $match[1];
                }
            }
        }
    }

    /** @return array<string, string> */
    private function headers(string $accept): array
    {
        return array_filter([
            'Accept' => $accept,
            'User-Agent' => 'omnihub',
            'X-GitHub-Api-Version' => '2022-11-28',
            'Authorization' => $this->token ? 'Bearer '.$this->token : null,
        ]);
    }
}
