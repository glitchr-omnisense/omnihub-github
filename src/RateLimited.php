<?php

namespace Omnihub\GitHub;

use Omnihub\Exception\UsageLimitReachedException;
use Omnisense\Contracts\Policy\RateLimit;

/**
 * GitHub's rate limit reached, or GitHub asked to wait (a 403 or a 429 with Retry-After, a
 * secondary limit), or the usage policy's limit reached (policy.json, as a site tightened it):
 * nothing is sent until $until, a Unix time. An omnihub UsageLimitReachedException, so
 * omnisense/contracts' UsageLimitReached too ($retryAfter, the seconds left when it was thrown).
 */
final class RateLimited extends UsageLimitReachedException
{
    /** The Unix time nothing is sent before. */
    public readonly int $until;

    public function __construct(string $message, ?int $retryAfter = null, ?string $provider = null, ?RateLimit $limit = null, ?\Throwable $previous = null, ?int $until = null)
    {
        parent::__construct($message, $retryAfter, $provider ?? 'GitHub', $limit, $previous);
        $this->until = $until ?? time() + max(0, $retryAfter ?? 0);
    }

    /** Nothing sent until $until, $now being the time now. */
    public static function until(int $until, int $now, ?RateLimit $limit = null, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('GitHub\'s rate limit is reached until %s UTC; nothing more is asked until then (a token, GITHUB_TOKEN, lifts it).', gmdate('Y-m-d H:i:s', $until)), max(0, $until - $now), 'GitHub', $limit, $previous, $until);
    }
}
