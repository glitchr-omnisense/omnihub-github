<?php

namespace Omnihub\GitHub;

use Omnihub\Source;

/**
 * A "github" source resolved at build time (bin/omnihub --resolve-github): the owner's PUBLIC
 * repositories whose names match the pattern and whose package.json, at the root, names a
 * package - each one a "git" source at its newest version tag, as Forge Store would have listed
 * them. A private repository is never read, even with a token that could: a static hub is public.
 * Without the flag the source stays in the manifest as it is, and the hub clients resolve it.
 */
final class Owner
{
    public function __construct(private readonly Api $api)
    {
    }

    /** @return list<Source> */
    public function resolve(Source $source): array
    {
        if (Source::GITHUB !== $source->type) {
            throw new \InvalidArgumentException(\sprintf('Only a "github" source is resolved, not a "%s" one.', $source->type));
        }
        $owner = (string) $source->owner;
        // /users/{owner}/repos lists an organisation's or a user's public repositories only.
        $repositories = $this->api->list('/users/'.rawurlencode($owner).'/repos?type=owner&sort=full_name&per_page=100');
        $resolved = [];
        foreach ($repositories as $repository) {
            $name = (string) ($repository['name'] ?? '');
            if (!self::isPublic($repository) || !$source->matches($name)) {
                continue;
            }
            $login = (string) ($repository['owner']['login'] ?? $owner);
            $package = $this->package($login, $name);
            if (null === $package) {
                continue;
            }
            $tag = $this->newestTag($login, $name);
            $resolved[strtolower($name)] = new Source(
                Source::GIT,
                'https://github.com/'.$login.'/'.$name.'.git'.(null === $tag ? '' : '#'.$tag),
                \is_string($package['displayName'] ?? null) && '' !== $package['displayName'] ? $package['displayName'] : $package['name'],
                install: $source->install,
                package: $package['name'],
            );
        }
        ksort($resolved);

        return array_values($resolved);
    }

    private static function isPublic(array $repository): bool
    {
        return false === ($repository['private'] ?? null) && 'public' === ($repository['visibility'] ?? 'public');
    }

    /** @return array{name: string, displayName?: mixed}|null the package.json at the root, when it names a package */
    private function package(string $owner, string $repository): ?array
    {
        $answer = $this->api->get('/repos/'.rawurlencode($owner).'/'.rawurlencode($repository).'/contents/package.json', 'application/vnd.github.raw+json');
        if (404 === $answer['status']) {
            return null;
        }
        if (200 !== $answer['status']) {
            throw new \RuntimeException(\sprintf('GitHub answered %d to %s/%s\'s package.json.', $answer['status'], $owner, $repository));
        }
        $json = json_decode($answer['body'], true);

        return \is_array($json) && \is_string($json['name'] ?? null) && '' !== $json['name'] ? $json : null;
    }

    /** The highest tag that reads as a version (v1.2.0, 1.2.0), a stable one first; none: the default branch. */
    private function newestTag(string $owner, string $repository): ?string
    {
        $newest = [null, null];
        foreach ($this->api->list('/repos/'.rawurlencode($owner).'/'.rawurlencode($repository).'/tags?per_page=100') as $tag) {
            $name = (string) ($tag['name'] ?? '');
            if (!preg_match('/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$/', $name, $match)) {
                continue;
            }
            $stable = (int) !str_contains($match[1], '-');
            if (null === $newest[$stable] || version_compare($match[1], $newest[$stable][1], '>')) {
                $newest[$stable] = [$name, $match[1]];
            }
        }

        return ($newest[1] ?? $newest[0])[0] ?? null;
    }
}
