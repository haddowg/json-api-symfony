<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecurityTestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Stands in for an app's own base test case whose `createClient()` returns a plain
 * {@see KernelBrowser} with a standing header, the case `InteractsWithJsonApi` can't serve.
 */
abstract class KernelBrowserWebTestCase extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return InMemorySecurityTestKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $server
     */
    protected static function createClient(array $options = [], array $server = []): KernelBrowser
    {
        return parent::createClient($options + ['debug' => false], $server + ['HTTP_X_SUITE' => 'app']);
    }
}
