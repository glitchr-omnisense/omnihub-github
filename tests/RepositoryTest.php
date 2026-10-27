<?php

namespace Omnihub\GitHub\Tests;

use Omnihub\GitHub\Repository;
use Omnihub\Tarball;
use Omnihub\Upm\Registry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** A repository's releases, as GitHub's API answers them, read into packages. */
class RepositoryTest extends TestCase
{
    public function testItsReleasesBecomeVersions(): void
    {
        $tgz = fn (string $v) => Tarball::create(['package.json' => json_encode(['name' => 'com.studio.one', 'version' => $v, 'unity' => '2021.3'])]);
        $asset = fn (string $v) => 'https://github.com/studio/one/releases/download/v'.$v.'/com.studio.one-'.$v.'.tgz';
        $releases = [
            ['tag_name' => 'v1.1.0', 'draft' => false, 'published_at' => '2026-10-02T10:00:00Z', 'body' => 'Faster.', 'assets' => [['name' => 'com.studio.one-1.1.0.tgz', 'browser_download_url' => $asset('1.1.0')], ['name' => 'notes.txt', 'browser_download_url' => 'https://x/notes.txt']]],
            ['tag_name' => 'v1.0.0', 'draft' => false, 'published_at' => '2026-10-01T10:00:00Z', 'body' => '', 'assets' => [['name' => 'com.studio.one-1.0.0.tgz', 'browser_download_url' => $asset('1.0.0')]]],
            ['tag_name' => 'v2.0.0', 'draft' => true, 'assets' => [['name' => 'com.studio.one-2.0.0.tgz', 'browser_download_url' => $asset('2.0.0')]]],
        ];
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($releases, $tgz, $asset, &$seen) {
            $seen[] = $url;
            if (str_starts_with($url, 'https://api.github.com/repos/studio/one/releases')) {
                self::assertContains('Authorization: Bearer secret', $options['headers']);

                return new MockResponse(json_encode($releases), ['response_headers' => ['content-type' => 'application/json']]);
            }
            foreach (['1.0.0', '1.1.0'] as $v) {
                if ($url === $asset($v)) {
                    return new MockResponse($tgz($v));
                }
            }

            return new MockResponse('', ['http_code' => 404]);
        });

        $packages = iterator_to_array((new Repository('studio/one', $client, 'secret'))->packages(), false);
        self::assertCount(1, $packages);
        $package = $packages[0];
        self::assertSame('com.studio.one', $package->name);
        self::assertSame(['1.0.0', '1.1.0'], array_map(fn ($v) => $v->version, $package->versions), 'the draft left out');
        self::assertSame('Faster.', $package->versions[1]->changelog);
        self::assertNull($package->versions[0]->changelog);
        self::assertSame(sha1($tgz('1.1.0')), $package->versions[1]->sha1);
        self::assertNotContains('https://x/notes.txt', $seen, 'only the tarballs downloaded');

        $document = Registry::document($package);
        self::assertSame($asset('1.1.0'), $document['versions']['1.1.0']['dist']['tarball'], 'the tarball stays on GitHub');
        self::assertSame('2021.3', $document['versions']['1.1.0']['unity']);
    }

    public function testARepositoryIsOwnerSlashName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Repository('not a repository', new MockHttpClient());
    }
}
