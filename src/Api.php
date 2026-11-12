<?php

namespace Omnihub\GitHub;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GitHub's REST API, read politely: every request carries the ETag of the answer kept for it
 * (If-None-Match) and a 304 reuses that answer - which costs nothing of the rate limit with a
 * token -; when X-RateLimit-Remaining reaches 0, or GitHub answers 403/429 with Retry-After,
 * nothing more is sent until the reset (RateLimited). Answers are kept in a JSON file between
 * builds when a path is given (a GitHub Action's cache), per token (a one-way mark, never the token).
 */
final class Api
{
    public const BASE = 'https://api.github.com';

    /** @var array<string, array{etag: string, status: int, body: string, link: string|null}> */
    private array $kept = [];
    private ?int $blockedUntil = null;
    private bool $changed = false;

    /** @param \Closure(): int|null $clock the time now, seconds (tests) */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ?string $token = null,
        private readonly ?string $cache = null,
        private readonly ?\Closure $clock = null,
    ) {
        if (null !== $cache && is_file($cache)) {
            $kept = json_decode((string) file_get_contents($cache), true);
            $this->kept = \is_array($kept) ? (array) ($kept[$this->account()] ?? []) : [];
        }
    }

    /** As bin/omnihub makes it: the token from GITHUB_TOKEN, the answers kept in $cache when given. */
    public static function fromEnvironment(?string $cache = null, ?HttpClientInterface $client = null): self
    {
        return new self($client ?? HttpClient::create(), getenv('GITHUB_TOKEN') ?: null, $cache);
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
        if (null !== $this->blockedUntil && $now < $this->blockedUntil) {
            throw new RateLimited($this->blockedUntil);
        }
        $key = $accept.' '.$url;
        $kept = $this->kept[$key] ?? null;
        $response = $this->client->request('GET', $url, ['headers' => array_filter([
            'Accept' => $accept,
            'User-Agent' => 'omnihub',
            'X-GitHub-Api-Version' => '2022-11-28',
            'Authorization' => $this->token ? 'Bearer '.$this->token : null,
            'If-None-Match' => $kept['etag'] ?? null,
        ])]);
        $status = $response->getStatusCode();
        $headers = $response->getHeaders(false);
        $header = fn (string $name) => $headers[$name][0] ?? null;

        $remaining = $header('x-ratelimit-remaining');
        $reset = $header('x-ratelimit-reset');
        $retry = $header('retry-after');
        if (\in_array($status, [403, 429], true) && (null !== $retry || '0' === $remaining)) {
            $this->blockedUntil = null !== $retry ? $now + max(1, (int) $retry) : (int) ($reset ?? $now + 60);
            throw new RateLimited($this->blockedUntil);
        }
        if ('0' === $remaining && null !== $reset) {
            $this->blockedUntil = (int) $reset; // this answer counts; the next one waits
        }

        if (304 === $status && null !== $kept) {
            return ['status' => $kept['status'], 'body' => $kept['body'], 'next' => self::next($kept['link'])];
        }
        $body = $response->getContent(false);
        $link = $header('link');
        $etag = $header('etag');
        if (200 === $status && null !== $etag) {
            $this->kept[$key] = ['etag' => $etag, 'status' => $status, 'body' => $body, 'link' => $link];
            $this->changed = true;
        }

        return ['status' => $status, 'body' => $body, 'next' => self::next($link)];
    }

    /** A JSON answer, every page of it when it is a list. */
    public function list(string $path): array
    {
        $items = [];
        for ($url = $path; null !== $url;) {
            $answer = $this->get($url);
            if (200 !== $answer['status']) {
                throw new \RuntimeException(\sprintf('GitHub answered %d to %s.', $answer['status'], $url));
            }
            $items = [...$items, ...(array) json_decode($answer['body'], true, 512, \JSON_THROW_ON_ERROR)];
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

    public function blockedUntil(): ?int
    {
        return $this->blockedUntil;
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
