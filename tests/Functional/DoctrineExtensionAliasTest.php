<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\ExtensionAlias\EaBookEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ExtensionAlias\EaHiddenRowEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ExtensionAlias\EaShelfEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ExtensionAlias\ExtensionAliasDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ExtensionAlias\HiddenRowsExtension;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\CollectingLogger;
use PHPUnit\Framework\Attributes\Test;

/**
 * A query extension runs on the type queried and again inside each subquery that scopes
 * a relation a filter, sort or include reaches through. Shelf 3 and book 2 are hidden.
 */
final class DoctrineExtensionAliasTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return ExtensionAliasDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        HiddenRowsExtension::$fixedAlias = false;
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $alpha = new EaShelfEntity(1, 'Alpha');
        $beta = new EaShelfEntity(2, 'Beta');
        $gamma = new EaShelfEntity(3, 'Gamma');
        foreach ([
            $alpha, $beta, $gamma,
            new EaBookEntity(1, 'Dune', $alpha),
            new EaBookEntity(2, 'Emma', $alpha),
            new EaBookEntity(3, 'Ivanhoe', $beta),
            new EaBookEntity(4, 'Kim', $gamma),
            new EaHiddenRowEntity('eaShelves', 3),
            new EaHiddenRowEntity('eaBooks', 2),
        ] as $row) {
            $entityManager->persist($row);
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function tearDown(): void
    {
        HiddenRowsExtension::$fixedAlias = false;
        parent::tearDown();
    }

    #[Test]
    public function aFixedAliasDefinedTwiceInOneQueryFailsWithAnErrorNamingTheExtension(): void
    {
        HiddenRowsExtension::$fixedAlias = true;
        $logger = static::getContainer()->get('logger');
        self::assertInstanceOf(CollectingLogger::class, $logger);
        $logger->reset();

        $response = $this->handle('/eaShelves?filter[legacyBookTitle]=Dune');

        self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());
        $messages = $logger->messages('error');
        self::assertCount(1, $messages);
        self::assertStringContainsString('"hidden_row" is defined twice', $messages[0]);
        self::assertStringContainsString(HiddenRowsExtension::class, $messages[0]);
        self::assertStringContainsString('ExtensionContext::uniqueAlias()', $messages[0]);
    }

    #[Test]
    public function aFixedAliasStillWorksWhereTheExtensionRunsOnce(): void
    {
        HiddenRowsExtension::$fixedAlias = true;

        self::assertSame(['1', '2'], $this->ids('/eaShelves'));
    }

    #[Test]
    public function uniqueAliasesServeTheRootRead(): void
    {
        self::assertSame(['1', '2'], $this->ids('/eaShelves'));
        self::assertSame(['1', '3', '4'], $this->ids('/eaBooks'));
    }

    #[Test]
    public function uniqueAliasesServeAFilterThroughARelation(): void
    {
        self::assertSame(['1'], $this->ids('/eaShelves?filter[legacyBookTitle]=Dune'));
        self::assertSame([], $this->ids('/eaShelves?filter[legacyBookTitle]=Emma'));
        self::assertSame(['2'], $this->ids('/eaShelves?filter[bookTitle]=Ivanhoe'));
        self::assertSame([], $this->ids('/eaBooks?filter[shelfName]=Gamma'));
    }

    #[Test]
    public function uniqueAliasesServeASortThroughARelation(): void
    {
        $ids = $this->ids('/eaBooks?sort=-shelfName');

        self::assertSame(['3', '1'], \array_values(\array_diff($ids, ['4'])));
        self::assertContains('4', $ids);
    }

    #[Test]
    public function uniqueAliasesServeAnInclude(): void
    {
        $response = $this->handle('/eaBooks?include=shelf');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['1', '2'], $this->includedIds($this->decode($response), 'eaShelves'));
    }

    #[Test]
    public function uniqueAliasesServeARelationshipQuery(): void
    {
        $response = $this->handle(
            '/eaShelves?include=books&relatedQuery[books][filter][shelfName]=Alpha',
            'GET',
            null,
            ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"'],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['1'], $this->includedIds($this->decode($response), 'eaBooks'));
    }

    /**
     * @return list<string>
     */
    private function ids(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        return \array_values(\array_map(static fn(mixed $resource): string => \is_array($resource) && \is_string($resource['id'] ?? null) ? $resource['id'] : '', $data));
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return list<string>
     */
    private function includedIds(array $document, string $type): array
    {
        $ids = [];
        foreach (\is_array($document['included'] ?? null) ? $document['included'] : [] as $resource) {
            if (\is_array($resource) && ($resource['type'] ?? null) === $type && \is_string($resource['id'] ?? null)) {
                $ids[] = $resource['id'];
            }
        }
        \sort($ids);

        return $ids;
    }
}
