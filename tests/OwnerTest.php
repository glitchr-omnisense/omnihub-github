<?php

namespace Omnihub\GitHub\Tests;

use Omnihub\GitHub\Api;
use Omnihub\GitHub\Owner;
use Omnihub\Source;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** A "github" source resolved at build time: the owner's public repositories that match, each a git source. */
class OwnerTest extends TestCase
{
    public function testThePublicMatchingRepositoriesBecomeGitSources(): void
    {
        $repositories = [
            ['name' => 'unity-tool', 'private' => false, 'visibility' => 'public', 'owner' => ['login' => 'acme']],
            ['name' => 'Unity-Paint', 'private' => false, 'visibility' => 'public', 'owner' => ['login' => 'acme']],
            ['name' => 'unity-secret', 'private' => true, 'visibility' => 'private', 'owner' => ['login' => 'acme']],
            ['name' => 'unity-internal', 'private' => false, 'visibility' => 'internal', 'owner' => ['login' => 'acme']],
            ['name' => 'unity-docs', 'private' => false, 'visibility' => 'public', 'owner' => ['login' => 'acme']],
            ['name' => 'website', 'private' => false, 'visibility' => 'public', 'owner' => ['login' => 'acme']],
        ];
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($repositories, &$seen) {
            $path = substr($url, \strlen('https://api.github.com'));
            $seen[] = $path;

            return match (true) {
                str_starts_with($path, '/users/acme/repos?') && !str_contains($path, 'page=2') => new MockResponse((string) json_encode(\array_slice($repositories, 0, 3)), ['response_headers' => ['link' => '<https://api.github.com/users/acme/repos?type=owner&per_page=100&page=2>; rel="next"']]),
                str_contains($path, 'page=2') => new MockResponse((string) json_encode(\array_slice($repositories, 3))),
                '/repos/acme/unity-tool/contents/package.json' === $path => new MockResponse((string) json_encode(['name' => 'com.acme.tool', 'displayName' => 'Acme Tool', 'version' => '1.2.0'])),
                '/repos/acme/Unity-Paint/contents/package.json' === $path => new MockResponse((string) json_encode(['name' => 'com.acme.paint'])),
                '/repos/acme/unity-docs/contents/package.json' === $path => new MockResponse('{"message":"Not Found"}', ['http_code' => 404]),
                str_starts_with($path, '/repos/acme/unity-tool/tags') => new MockResponse((string) json_encode([['name' => 'v1.10.0-beta'], ['name' => 'v1.2.0'], ['name' => 'v1.10.0'], ['name' => 'nightly']])),
                str_starts_with($path, '/repos/acme/Unity-Paint/tags') => new MockResponse('[]'),
                default => new MockResponse('', ['http_code' => 500]),
            };
        });

        $sources = (new Owner(new Api($client)))->resolve(Source::github('acme', 'unity-*', 'Acme', true));

        self::assertSame([
            ['name' => 'com.acme.paint', 'type' => 'git', 'url' => 'https://github.com/acme/Unity-Paint.git', 'package' => 'com.acme.paint', 'install' => true],
            ['name' => 'Acme Tool', 'type' => 'git', 'url' => 'https://github.com/acme/unity-tool.git#v1.10.0', 'package' => 'com.acme.tool', 'install' => true],
        ], array_map(fn (Source $s) => $s->toArray(), $sources), 'sorted by repository name, case ignored; the newest stable tag, no tag: the default branch');
        self::assertEmpty(array_filter($seen, fn ($p) => str_contains($p, 'unity-secret') || str_contains($p, 'unity-internal') || str_contains($p, 'website')), 'a private, an internal or an unmatched repository is never read');
    }

    public function testOnlyAGitHubSourceIsResolved(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Owner(new Api(new MockHttpClient())))->resolve(new Source(Source::GIT, 'https://github.com/acme/unity-tool.git'));
    }
}
