<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\IncludeScopingDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingFixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * A pivot `belongsToMany` (through {@see \haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingPlacement})
 * to a type a Doctrine query extension scopes renders only the scoped members, with
 * `meta.pivot` for those alone (bundle ADR 0035). Agency 2 is hidden; its placements
 * carry the role "Secret Role".
 */
final class IncludeScopingPivotTest extends JsonApiFunctionalTestCase
{
    use IncludeScopingAssertions;

    protected static function getKernelClass(): string
    {
        return IncludeScopingDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (ScopingFixtures::graph() as $entities) {
            foreach ($entities as $entity) {
                $entityManager->persist($entity);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aCollectionIncludeOfAPivotRelationIsScoped(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'advertisers?include=placedAgencies');

        self::assertSame(['1', '3'], $this->includedIds($document, $prefix . 'agencies'));
        self::assertSame(['1'], $this->linkageIds($document, '1', 'placedAgencies'));
        self::assertSame(['3'], $this->linkageIds($document, '2', 'placedAgencies'));
        self::assertSame(['Lead'], $this->roles($document, '1', 'placedAgencies'));
        self::assertSame(['Support'], $this->roles($document, '2', 'placedAgencies'));
        $this->assertNoHiddenRow($document);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aSingleResourceIncludeOfAPivotRelationIsScoped(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'advertisers/1?include=placedAgencies');

        self::assertSame(['1'], $this->includedIds($document, $prefix . 'agencies'));
        self::assertSame(['Lead'], $this->roles($document, '1', 'placedAgencies'));
        $this->assertNoHiddenRow($document);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function alwaysEmittedPivotLinkageWithoutAnIncludeIsScoped(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'advertisers');

        self::assertSame(['Lead'], $this->roles($document, '1', 'listedPlacedAgencies'));
        self::assertSame(['Support'], $this->roles($document, '2', 'listedPlacedAgencies'));
        $this->assertNoHiddenRow($document);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aNestedIncludeThroughAPivotRelationIsScoped(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'advertisers?include=placedAgencies.members');

        self::assertSame(['1', '3'], $this->includedIds($document, $prefix . 'members'));
        $this->assertNoHiddenRow($document);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function thePivotWindowIsScoped(string $mount, string $prefix): void
    {
        $document = $this->get(
            $mount . '/' . $prefix . 'advertisers?include=placedAgencies&relatedQuery[placedAgencies][sort]=-name',
            ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"'],
        );

        self::assertSame(['Lead'], $this->roles($document, '1', 'placedAgencies'));
        self::assertSame(['Support'], $this->roles($document, '2', 'placedAgencies'));
        $this->assertNoHiddenRow($document);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function thePivotRelatedAndRelationshipEndpointsAreScoped(string $mount, string $prefix): void
    {
        $base = $mount . '/' . $prefix . 'advertisers/2';

        $related = $this->get($base . '/placedAgencies?page[size]=1');
        self::assertSame(['3'], $this->ids($related['data']));
        $this->assertNoHiddenRow($related);

        $relationship = $this->get($base . '/relationships/placedAgencies?page[size]=1');
        self::assertSame(['3'], $this->ids($relationship['data']));
        self::assertNull($this->value($relationship, 'links', 'next'));
        $this->assertNoHiddenRow($relationship);
    }

    /**
     * The `meta.pivot.role` of each linkage member, in linkage order.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function roles(array $document, string $id, string $name): array
    {
        $data = $this->value($this->relationship($document, $id, $name), 'data');
        self::assertIsArray($data);

        $roles = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $role = $this->value($identifier, 'meta', 'pivot', 'role');
            self::assertIsString($role);
            $roles[] = $role;
        }

        return $roles;
    }
}
