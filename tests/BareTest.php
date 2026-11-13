<?php

namespace Omnihub\GitHub\Tests;

use Omnihub\GitHub\Repository;
use Omnihub\StaticHub;
use Omnisense\Contracts\Testing\BareTestCase;

/**
 * omnihub/github with no framework: every class loads with nothing from outside but
 * omnisense/contracts, omnihub's core and Symfony's HTTP client contracts - symfony/http-client
 * itself only when no client is given (fromEnvironment()).
 */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return Repository::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }

    /** The classes sit under src/, the package's PSR-4 root. */
    protected static function packageDir(): string
    {
        return self::dirOf(Repository::class).'/src';
    }

    protected static function coreDirs(): array
    {
        return [...parent::coreDirs(), self::dirOf(StaticHub::class)];
    }
}
