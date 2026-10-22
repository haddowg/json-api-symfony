<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RouteCheck;

use Symfony\Component\HttpFoundation\Response;

/**
 * An application route the bundle didn't emit.
 */
final class PlainController
{
    public function __invoke(): Response
    {
        return new Response('plain');
    }
}
