<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Serializer;

use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Serializer\PivotMetaSerializer;
use haddowg\JsonApiBundle\Serializer\PivotSubstitutingResolver;
use haddowg\JsonApiBundle\Tests\Functional\App\SingletonBuild\PlainSettingsResource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PivotSubstitutingResolverTest extends TestCase
{
    #[Test]
    public function itPassesTheServersSingletonsThrough(): void
    {
        $server = Server::make()->register(PlainSettingsResource::class);
        $resolver = new PivotSubstitutingResolver($server, 'settings', new PivotMetaSerializer($server->serializerFor('settings'), []));

        self::assertTrue($resolver->isSingleton('settings'));
        self::assertFalse($resolver->isSingleton('articles'));
        self::assertSame('1', $resolver->resolveSingletonId('settings', new SingletonContext('settings')));
    }

    #[Test]
    public function itPassesTheServersResourcesAndSubstitutesThrough(): void
    {
        $server = Server::make()->register(PlainSettingsResource::class);
        $settings = $server->serializerFor('settings');
        $other = new PivotMetaSerializer($settings, []);
        $resolver = new PivotSubstitutingResolver($server, 'settings', new PivotMetaSerializer($settings, []), ['others' => $other]);

        self::assertTrue($resolver->hasResourceFor('settings'));
        self::assertSame($server->resourceFor('settings'), $resolver->resourceFor('settings'));
        self::assertFalse($resolver->hasResourceFor('articles'));
        self::assertTrue($resolver->hasSerializerFor('others'));
        self::assertSame($other, $resolver->serializerFor('others'));
    }
}
