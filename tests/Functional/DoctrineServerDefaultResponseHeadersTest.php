<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OpenWidgetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\ServerDefaultsResponseHeadersTestKernel;

/**
 * {@see ServerDefaultResponseHeadersConformance} on the Doctrine provider.
 */
final class DoctrineServerDefaultResponseHeadersTest extends JsonApiFunctionalTestCase
{
    use ServerDefaultResponseHeadersConformance;

    protected static function getKernelClass(): string
    {
        return ServerDefaultsResponseHeadersTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        $entityManager->persist(new OpenWidgetEntity(1, 'one'));
        $entityManager->flush();
        $entityManager->clear();
    }
}
