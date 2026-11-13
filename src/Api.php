<?php

namespace Omnihub\GitHub;

use Omnihub\Exception\ProviderException;
use Omnihub\Exception\UsageLimitReachedException;
use Omnihub\Http\JsonClient;
use Omnisense\Contracts\Http\Answer;
use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\LegacyReset;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\RateLimiterInterface;
use Omnisense\Contracts\Policy\RateLimitHeaders;
use Omnisense\Contracts\Policy\UsageLimitReached;
use Omnisense\Contracts\Policy\UsagePolicy;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GitHub's REST API, read politely, on omnisense/contracts' kit:
 *
 * - every request goes through omnihub's JsonClient: sent once (Http\NoRetry: a refusal is never
 *   replayed by the application's client), omnihub's User-Agent, a timeout, a network failure
 *   thrown without the token (Redaction);
 * - every request carries the ETag of the answer kept for it (If-None-Match) and a 304 reuses
 *   that answer - which costs nothing of the rate limit with a token -; answers are kept in a
 *   JSON file between builds when a path is given (a GitHub Action's cache), per token (a
 *   one-way mark, never the token);
 * - GitHub's limits are honoured through the kit's Backoff, in the limiter given (shared with
 *   other processes) or one of its own: when X-RateLimit-Remaining reaches 0 nothing more is
 *   sent until X-RateLimit-Reset; a 403 or a 429 with Retry-After (a secondary limit), or with
 *   nothing remaining, holds every request until the wait is over (RateLimited);
 * - the usage policy declared in policy.json (its "anonymous" or "token" plan; a site's
 *   tightened one when given) is checked before each request and counted after it, by the
 *   kit's PolicyGuard: past it, nothing is sent (RateLimited).
 */
final class Api
{
    public const BASE = 'https://api.github.com';

    /** The usage policy this provider declares (extra.omnisense.policy). */
    public const POLICY = __DIR__.'/../policy.json';

    /** GitHub's error statuses given back to the caller rather than thrown (a 404: not there). */
    private const PASS = [400, 401, 403, 404, 409, 410, 422, 429, 451, 500, 502, 503, 504];

    /** @var array<string, array{etag: string, status: int, body: string, link: string|null}> */
    private array $kept = [];
    private bool $changed = false;
    private readonly JsonClient $json;
    private readonly Backoff $backoff;
    private readonly PolicyGuard $guard;

    /**
     * @param (\Closure(): int)|null    $clock   the time now, seconds (tests)
     * @param RateLimiterInterface|null $limiter where the waits and the policy's counts are kept (shared across processes: omnibridge/symfony's); one of its own otherwise
     * @param UsagePolicy|null          $policy  the usage policy, as a site tightened it (UsagePolicy::tighten()); policy() otherwise
     */
    public function __construct(
        HttpClientInterface $client,
        #[\SensitiveParameter] private readonly ?string $token = null,
        private readonly ?string $cache = null,
        private readonly ?\Closure $clock = null,
        ?RateLimiterInterface $limiter = null,
        ?UsagePolicy $policy = null,
    ) {
        $limiter ??= new InMemoryRateLimiter(null !== $clock ? static fn (): float => (float) $clock() : null);
        // GitHub's X-RateLimit-Reset is a Unix time.
        $this->backoff = (new Backoff($limiter, 'GitHub', UsageLimitReachedException::class))->withLegacyHeaders(LegacyReset::Epoch);
        $this->guard = new PolicyGuard($policy ?? self::policy(null !== $token), $limiter, $this->backoff, UsageLimitReachedException::class);
        $this->json = new JsonClient($client, 'GitHub', array_filter([
            'X-GitHub-Api-Version' => '2022-11-28',
            'Authorization' => null !== $token && '' !== $token ? 'Bearer '.$token : null,
        ], static fn (?string $value): bool => null !== $value));
        if (null !== $cache && is_file($cache)) {
            $kept = json_decode((string) file_get_contents($cache), true);
            $this->kept = \is_array($kept) && \is_array($kept[$this->account()] ?? null) ? self::kept($kept[$this->account()]) : [];
        }
    }

    /** As bin/omnihub makes it: the token from GITHUB_TOKEN, the answers kept in $cache when given. */
    public static function fromEnvironment(?string $cache = null, ?HttpClientInterface $client = null, ?RateLimiterInterface $limiter = null): self
    {
        return new self($client ?? HttpClient::create(), getenv('GITHUB_TOKEN') ?: null, $cache, limiter: $limiter);
    }

    /** The usage policy declared in policy.json, under its "token" plan or its "anonymous" one. */
    public static function policy(bool $token = false): UsagePolicy
    {
        return UsagePolicy::fromFile(self::POLICY)->forPlan($token ? 'token' : 'anonymous');
    }

    /**
     * A GET on the API: its status (a 304 answered from the kept copy, as 200), its body, the
     * next page's address (Link: rel="next").
     *
     * @return array{status: int, body: string, next: string|null}
     */
    public function get(string $path, string $accept = 'application/vnd.github+json'): array
    {
        $url = str_starts_with($path, 'https://') ? $path : self::BASE.$path;
        if (!str_starts_with($url, self::BASE.'/')) {
            throw new \InvalidArgumentException(\sprintf('Only GitHub\'s API is read, not "%s".', $url));
        }
        $now = $this->now();
        $key = $accept.' '.$url;
        $kept = $this->kept[$key] ?? null;
        try {
            $answer = $this->guard->call('api', fn (): Answer => $this->send($url, $accept, $kept['etag'] ?? null, $now), ['key' => $this->account()]);
        } catch (UsageLimitReached $e) {
            throw $e instanceof RateLimited ? $e : RateLimited::until($now + max(1, $e->retryAfter ?? 60), $now, $e->limit, $e);
        }

        if (304 === $answer->status && null !== $kept) {
            return ['status' => $kept['status'], 'body' => $kept['body'], 'next' => self::next($kept['link'])];
        }
        $link = $answer->header('link');
        $etag = $answer->header('etag');
        if (200 === $answer->status && null !== $etag) {
            $this->kept[$key] = ['etag' => $etag, 'status' => $answer->status, 'body' => $answer->body, 'link' => $link];
            $this->changed = true;
        }

        return ['status' => $answer->status, 'body' => $answer->body, 'next' => self::next($link)];
    }

    /**
     * A JSON answer, every page of it when it is a list.
     *
     * @return list<array<mixed>>
     */
    public function list(string $path): array
    {
        $items = [];
        for ($url = $path; null !== $url;) {
            $answer = $this->get($url);
            if (200 !== $answer['status']) {
                throw ProviderException::answered('GitHub', 'GET '.(parse_url($url, \PHP_URL_PATH) ?: $url), $answer['status'], $answer['body']);
            }
            $page = json_decode($answer['body'], true, 512, \JSON_THROW_ON_ERROR);
            foreach (\is_array($page) ? $page : [] as $item) {
                $items[] = \is_array($item) ? $item : [];
            }
            $url = $answer['next'];
        }

        return $items;
    }

    /** The kept answers written to the cache file, when one was given. */
    public function save(): void
    {
        if (null === $this->cache || !$this->changed) {
            return;
        }
        $all = is_file($this->cache) ? json_decode((string) file_get_contents($this->cache), true) : [];
        $all = \is_array($all) ? $all : [];
        $all[$this->account()] = $this->kept;
        if (!is_dir(\dirname($this->cache))) {
            @mkdir(\dirname($this->cache), 0777, true);
        }
        file_put_contents($this->cache, json_encode($all, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        $this->changed = false;
    }

    /** The Unix time nothing is sent before; null when nothing holds the requests. */
    public function blockedUntil(): ?int
    {
        $left = $this->backoff->left();

        return $left > 0 ? $this->now() + $left : null;
    }

    /**
     * The request on the wire, and GitHub's refusals read: a 403 with Retry-After or nothing
     * remaining, and any 429, hold every request until the wait is over; any other answer's
     * X-RateLimit-Remaining 0 holds the next requests until X-RateLimit-Reset (the kit's
     * Backoff, which reads the RateLimit and Retry-After fields too).
     */
    private function send(string $url, string $accept, ?string $etag, int $now): Answer
    {
        $answer = $this->json->raw('GET', $url, [], array_filter(['Accept' => $accept, 'If-None-Match' => $etag], static fn (?string $value): bool => null !== $value), self::PASS);
        $status = $answer->status;
        if (403 === $status || 429 === $status) {
            $retry = RateLimitHeaders::retryAfter($answer->header('retry-after'), $now);
            $remaining = $answer->header('x-ratelimit-remaining');
            if (429 === $status || null !== $retry || '0' === $remaining) {
                $reset = $answer->header('x-ratelimit-reset');
                $seconds = match (true) {
                    null !== $retry => $retry,
                    '0' === $remaining && null !== $reset && is_numeric($reset) => (int) $reset - $now,
                    default => 60,
                };

                throw $this->backoff->hold(max(1, $seconds), \sprintf('GitHub answered %d', $status));
            }
        }
        $this->backoff->observe($status, $answer->headers, $now);

        return $answer;
    }

    /**
     * The kept answers read back from the cache file: those of the expected shape.
     *
     * @param array<mixed> $kept
     *
     * @return array<string, array{etag: string, status: int, body: string, link: string|null}>
     */
    private static function kept(array $kept): array
    {
        $valid = [];
        foreach ($kept as $key => $answer) {
            if (\is_string($key) && \is_array($answer) && \is_string($answer['etag'] ?? null) && \is_int($answer['status'] ?? null) && \is_string($answer['body'] ?? null)) {
                $valid[$key] = ['etag' => $answer['etag'], 'status' => $answer['status'], 'body' => $answer['body'], 'link' => \is_string($answer['link'] ?? null) ? $answer['link'] : null];
            }
        }

        return $valid;
    }

    private function account(): string
    {
        return null === $this->token ? 'anonymous' : substr(hash('sha256', 'omnihub:'.$this->token), 0, 16);
    }

    private function now(): int
    {
        return null !== $this->clock ? ($this->clock)() : time();
    }

    private static function next(?string $link): ?string
    {
        return null !== $link && preg_match('/<([^>]+)>;\s*rel="next"/', $link, $match) ? $match[1] : null;
    }
}
