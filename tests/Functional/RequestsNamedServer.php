<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\ResourcesOnServerPass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a suite's requests to the named server a
 * {@see App\NamedServer\ServesOnNamedServer} kernel mounts, leaving a path that
 * already names it, such as a followed link, as it is.
 *
 * @phpstan-require-extends JsonApiFunctionalTestCase
 */
trait RequestsNamedServer
{
    protected function handle(string $path, string $method = 'GET', ?array $body = null, array $extraServer = []): Response
    {
        $prefix = '/' . ResourcesOnServerPass::SERVER;
        if (!\str_starts_with($path, $prefix . '/')) {
            $path = $prefix . $path;
        }

        return parent::handle($path, $method, $body, $extraServer);
    }
}
