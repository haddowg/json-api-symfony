<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OwnedFolderEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OwnedNoteEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\UserScopingTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * The authorization guide's per-user scoping examples: `ownedNotes` limited to the
 * caller's notes by a Doctrine extension, and `memos` served only to their owner by a
 * custom scoped provider. Every row and count that reaches either type follows the
 * scope.
 *
 * Seeded: folder 1 (ada) holds notes 1 (ada), 2 (grace) and 3 (ada), and memos 1 (ada),
 * 2 (grace) and 3 (ada).
 */
class DoctrineUserScopingTest extends JsonApiFunctionalTestCase
{
    use UserScopingConformance;

    protected static function getKernelClass(): string
    {
        return UserScopingTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $folder = new OwnedFolderEntity(1, 'ada');
        $entityManager->persist($folder);
        foreach ([1 => 'ada', 2 => 'grace', 3 => 'ada'] as $id => $owner) {
            $entityManager->persist(new OwnedNoteEntity($id, $owner, $folder));
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function the_extension_limits_the_collection_and_its_total(): void
    {
        $document = $this->scopedDocument('ada', '/ownedNotes?page[number]=1&page[size]=10&withCount=_self_');
        self::assertSame(['1', '3'], $this->scopedIds($document['data'] ?? null));
        self::assertSame(2, $this->scopedValue($document, 'meta', 'page', 'total'));
    }

    #[Test]
    public function the_extension_limits_every_relationship_that_reaches_the_type(): void
    {
        $this->assertScopedRelation('ada', '/ownedFolders/1', 'notes', ['1', '3']);
        $this->assertScopedRelation('grace', '/ownedFolders/1', 'notes', ['2']);
    }

    #[Test]
    public function the_extension_limits_a_relationship_query_window_and_its_count(): void
    {
        $profiles = ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . ' ' . RelationshipQueriesProfile::URI . '"'];

        foreach (['ada' => ['1', '3'], 'grace' => []] as $owner => $expected) {
            $document = $this->scopedDocument('ada', '/ownedFolders/1?include=notes&withCount=notes&relatedQuery[notes][filter][owner]=' . $owner, $profiles);
            $relationship = $this->scopedRelationship($document, 'notes');
            self::assertSame($expected, $this->scopedIds($relationship['data'] ?? null), $owner);
            self::assertSame(\count($expected), $this->scopedValue($relationship, 'meta', 'total'), $owner);
        }
    }
}
