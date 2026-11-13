<?php

namespace Omnihub\GitHub\Tests;

use Omnihub\Exception\OmnihubException;
use Omnihub\Exception\UnreachableException;
use Omnihub\GitHub\Api;
use Omnihub\GitHub\RateLimited;
use Omnihub\Http\JsonClient;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\UsageLimitReached;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;

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
            $headers = \is_array($options['normalized_headers'] ?? null) ? $options['normalized_headers'] : [];
            $sent[] = \is_array($headers['if-none-match'] ?? null) ? ($headers['if-none-match'][0] ?? null) : null;

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
            self::assertArrayNotHasKey('if-none-match', \is_array($options['normalized_headers'] ?? null) ? $options['normalized_headers'] : [], 'another token, another account');

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

    public function testEveryRequestCarriesOmnihubsUserAgentAndATimeout(): void
    {
        $options = [];
        $api = new Api(new MockHttpClient(function (string $method, string $url, array $sent) use (&$options) {
            $options = $sent;

            return new MockResponse('[]');
        }), 'not-a-real-token');
        $api->list('/users/acme/repos');

        self::assertIsArray($options['headers'] ?? null);
        self::assertContains('User-Agent: '.JsonClient::USER_AGENT, $options['headers']);
        self::assertContains('X-GitHub-Api-Version: 2022-11-28', $options['headers']);
        self::assertSame(JsonClient::TIMEOUT, $options['timeout'] ?? null);
    }

    public function testA429IsHonouredAndTheNextRequestHeld(): void
    {
        $calls = 0;
        $api = new Api(new MockHttpClient(function () use (&$calls) {
            ++$calls;

            return new MockResponse('{"message":"too many"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '120']]);
        }), clock: fn () => 1_000);
        foreach ([1, 2] as $attempt) {
            try {
                $api->get('/users/acme/repos');
                self::fail('A 429 stops the reading.');
            } catch (RateLimited $e) {
                self::assertInstanceOf(UsageLimitReached::class, $e, 'omnisense/contracts\' refusal');
                self::assertInstanceOf(OmnihubException::class, $e);
                self::assertSame(1_120, $e->until);
                self::assertSame(120, $e->retryAfter);
            }
        }
        self::assertSame(1, $calls, 'the second request held, never sent');
        self::assertSame(1_120, $api->blockedUntil());
    }

    public function testThePolicysLimitIsReachedWithTheRequestNeverMade(): void
    {
        $calls = 0;
        $policy = Api::policy()->tighten(['rateLimits' => [['max' => 2, 'window' => 'hour', 'operations' => []]]]);
        $api = new Api(new MockHttpClient(function () use (&$calls) {
            ++$calls;

            return new MockResponse('[]', ['response_headers' => ['x-ratelimit-remaining' => '50']]);
        }), clock: fn () => 1_000, policy: $policy);
        $api->list('/users/acme/repos');
        $api->list('/users/acme/repos?page=2');
        try {
            $api->list('/users/acme/repos?page=3');
            self::fail('The site\'s limit is respected.');
        } catch (RateLimited $e) {
            self::assertSame(2, $e->limit?->max);
            self::assertSame(4_600, $e->until, 'the hour that began with the first request');
        }
        self::assertSame(2, $calls);
    }

    public function testASiteTightensThePolicyAndCannotLoosenIt(): void
    {
        self::assertSame('anonymous', Api::policy()->plan);
        self::assertSame('token', Api::policy(true)->plan);
        self::assertSame(5000, Api::policy(true)->limitsFor('api')[0]->max ?? null);
        self::assertCount(2, Api::policy()->tighten(['rateLimits' => [['max' => 30, 'window' => 'hour', 'operations' => []]]])->limitsFor('api'));

        $this->expectException(InvalidPolicyException::class);
        Api::policy()->tighten(['rateLimits' => [['max' => 120, 'window' => 'hour', 'scope' => 'client-ip', 'operations' => []]]]);
    }

    public function testARefusalIsNotReplayedByARetryingClient(): void
    {
        $mock = new MockHttpClient([
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => '5']]),
            new MockResponse('[]'),
        ]);
        $api = new Api(new RetryableHttpClient($mock, new GenericRetryStrategy(GenericRetryStrategy::DEFAULT_RETRY_STATUS_CODES, 0), 3));
        try {
            $api->list('/users/acme/repos');
            self::fail('The 429 reaches omnihub/github.');
        } catch (RateLimited $e) {
            self::assertSame(5, $e->retryAfter);
        }
        self::assertSame(1, $mock->getRequestsCount(), 'sent once: the wait is GitHub\'s, not the client\'s to sleep');
    }

    public function testANetworkFailureNeverShowsTheToken(): void
    {
        $api = new Api(new MockHttpClient(new MockResponse('', ['error' => 'Connection reset by peer'])), 'not-a-real-token');
        try {
            $api->get('/users/acme/repos');
            self::fail('No answer.');
        } catch (UnreachableException $e) {
            self::assertStringNotContainsString('not-a-real-token', $e->getMessage());
            self::assertStringNotContainsString('not-a-real-token', (string) $e->getPrevious()?->getMessage());
        }
    }
}
