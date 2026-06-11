<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The constructor-less instantiation witness over the Doctrine-sqlite provider:
 * the `vaults` entity ({@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\VaultEntity})
 * has **required** constructor arguments, so `new VaultEntity()` would `TypeError`.
 * A create therefore only succeeds because the reference Doctrine persister builds
 * the instance via Doctrine's constructor-less instantiation
 * (`ClassMetadata::newInstance()`), the same mechanism the ORM uses on read
 * (ADR 0029). The schema is created in `afterBoot()`; no seeding is needed.
 */
final class DoctrineInstantiationTest extends JsonApiFunctionalTestCase
{
    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $entityManager->clear();
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingAnEntityWithARequiredConstructorArgumentPersists(): void
    {
        $response = $this->handle('/vaults', 'POST', [
            'data' => [
                'type' => 'vaults',
                'attributes' => ['secret' => 's3cr3t'],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());

        $created = $this->decode($response)['data'] ?? null;
        self::assertIsArray($created);
        self::assertSame('vaults', $created['type'] ?? null);

        $id = $created['id'] ?? null;
        self::assertIsString($id);
        self::assertNotSame('', $id);

        $attributes = $created['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('s3cr3t', $attributes['secret'] ?? null);

        // The entity built without its constructor was persisted: re-fetch it.
        $getResponse = $this->handle('/vaults/' . $id);
        self::assertSame(200, $getResponse->getStatusCode());

        $fetched = $this->decode($getResponse)['data'] ?? null;
        self::assertIsArray($fetched);

        $fetchedAttributes = $fetched['attributes'] ?? null;
        self::assertIsArray($fetchedAttributes);
        self::assertSame('s3cr3t', $fetchedAttributes['secret'] ?? null);
    }

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }
}
