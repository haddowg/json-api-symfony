<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OpenWidgetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\BothServersResponseHeadersTestKernel;

/**
 * {@see ServerKeyedResponseHeadersConformance} and {@see ServerKeyedOpenApiTagsConformance} on the Doctrine provider.
 */
final class DoctrinePerServerResourceConfigTest extends JsonApiFunctionalTestCase
{
    use ServerKeyedOpenApiTagsConformance;
    use ServerKeyedResponseHeadersConformance;

    protected static function getKernelClass(): string
    {
        return BothServersResponseHeadersTestKernel::class;
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
