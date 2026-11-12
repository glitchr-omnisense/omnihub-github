<?php

namespace Omnihub\GitHub;

/** GitHub's rate limit reached: nothing is sent until its reset. */
final class RateLimited extends \RuntimeException
{
    public function __construct(public readonly int $until)
    {
        parent::__construct(\sprintf('GitHub\'s rate limit is reached until %s UTC; nothing more is asked until then (a token, GITHUB_TOKEN, lifts it).', gmdate('Y-m-d H:i:s', $until)));
    }
}
