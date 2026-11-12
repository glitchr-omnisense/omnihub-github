<?php

namespace Omnihub\GitHub\Tests;

use Omnihub\GitHub\Api;
use Omnihub\GitHub\RateLimited;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** GitHub's API read politely: ETags kept between builds, the rate limit respected. */
class ApiTest extends TestCase
{
    public function testAnETagIsSentAndA304ReusesTheKeptAnswer(): void
    {
        $cache = sys_get_temp_dir().'/omnihub-github-'.bin2hex(random_bytes(4)).'.json';
        $sent = [];
        $answers = [
            new MockResponse('[{"name":"unity-tool"}]', ['response_headers' => ['etag' => '"one"', 'x-ratelimit-remaining' => '59']]),
            new MockResponse('', ['http_code' => 304, 'response_headers' => ['etag' => '"one"']]),
        ];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent, &$answers) {
            $sent[] = $options['normalized_headers']['if-none-match'][0] ?? null;

            return array_shift($answers);
        });

        $first = new Api($client, 'secret', $cache);
        self::assertSame([['name' => 'unity-tool']], $first->list('/users/acme/repos'));
        $first->save();
        self::assertStringNotContainsString('secret', (string) file_get_contents($cache), 'a mark of the token, never the token');

        $second = new Api($client, 'secret', $cache);
        self::assertSame([['name' => 'unity-tool']], $second->list('/users/acme/repos'), 'the 304 answered from the kept copy');
        self::assertSame([null, 'If-None-Match: "one"'], $sent);

        $other = new Api(new MockHttpClient(function (string $method, string $url, array $options) {
            self::assertArrayNotHasKey('if-none-match', $options['normalized_headers'], 'another token, another account');

            return new MockResponse('[]');
        }), 'another', $cache);
        $other->list('/users/acme/repos');
        unlink($cache);
    }

    public function testNothingIsSentOnceTheLimitIsReached(): void
    {
        $now = 1_000;
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            ++$calls;

            return new MockResponse('[]', ['response_headers' => ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1600']]);
        });
        $api = new Api($client, clock: function () use (&$now) { return $now; });
        $api->list('/users/acme/repos');
        try {
            $api->list('/users/acme/repos');
            self::fail('The limit is respected.');
        } catch (RateLimited $e) {
            self::assertSame(1600, $e->until);
            self::assertSame(1, $calls, 'nothing sent before the reset');
        }
        $now = 1_600;
        $api->list('/users/acme/repos');
        self::assertSame(2, $calls, 'sent again after it');
    }

    public function testARetryAfterIsHonoured(): void
    {
        $calls = 0;
        $api = new Api(new MockHttpClient(function () use (&$calls) {
            ++$calls;

            return new MockResponse('{"message":"secondary rate limit"}', ['http_code' => 403, 'response_headers' => ['retry-after' => '30']]);
        }), clock: fn () => 100);
        foreach ([1, 2] as $attempt) {
            try {
                $api->get('/users/acme/repos');
                self::fail('A 403 with Retry-After stops the reading.');
            } catch (RateLimited $e) {
                self::assertSame(130, $e->until);
            }
        }
        self::assertSame(1, $calls);
    }

    public function testOnlyGitHubsApiIsRead(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Api(new MockHttpClient()))->get('https://example.org/users/acme');
    }
}
