<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The named server's own response-header defaults: a sunset and its
 * link, a 30s cache varying on Accept-Language, and no deprecation of its own.
 */
final class ServerHeaderDefaults
{
    public const string SUNSET = 'Sat, 01 May 2027 00:00:00 GMT';

    public const string SUNSET_LINK = 'https://example.test/v6';

    public static function apply(ContainerConfigurator $container): void
    {
        $container->extension('json_api', ['servers' => [ResourcesOnServerPass::SERVER => ['defaults' => [
            'sunset' => self::SUNSET,
            'sunset_link' => self::SUNSET_LINK,
            'cache_headers' => ['max_age' => 30, 'vary' => ['Accept-Language']],
        ]]]]);
    }
}
