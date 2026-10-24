<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OpenWidgetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OwnedFolderEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OwnedNoteEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecuredWidgetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecuredWidgetTagEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecurityTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * The relation authorizer on the Doctrine provider: a relation's read expression
 * decides whether its data renders on a fetched or included resource, and a flattened
 * `on()` write needs the update expression of the row it lands on.
 *
 * Seeded: securedWidgets 1 and 2 are each other's partner, and widget 1 holds
 * openWidget 1 through the `adminTags` pivot. Folders 1 and 3 belong to ada, folder 2
 * to grace; note 1 (ada) is in folder 1 and note 2 (ada) in grace's folder 2.
 */
final class DoctrineRelationAuthorizerTest extends JsonApiFunctionalTestCase
{
    use RelationAuthorizerConformance;

    protected static function getKernelClass(): string
    {
        return SecurityTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // Ids are generated, so insert both widgets before linking them.
        $one = new SecuredWidgetEntity(1, 'one');
        $two = new SecuredWidgetEntity(2, 'two');
        $tag = new OpenWidgetEntity(1, 'tag');
        $entityManager->persist($one);
        $entityManager->persist($two);
        $entityManager->persist($tag);
        $entityManager->flush();
        $one->partner = $two;
        $two->partner = $one;
        $entityManager->persist(new SecuredWidgetTagEntity(null, $one, $tag, 1));

        $folders = [1 => new OwnedFolderEntity(1, 'ada'), 2 => new OwnedFolderEntity(2, 'grace'), 3 => new OwnedFolderEntity(3, 'ada')];
        foreach ($folders as $folder) {
            $entityManager->persist($folder);
        }
        $entityManager->persist(new OwnedNoteEntity(1, 'ada', $folders[1]));
        $entityManager->persist(new OwnedNoteEntity(2, 'ada', $folders[2]));

        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function a_pivot_relation_the_caller_may_not_read_renders_no_linkage_or_included_data(): void
    {
        $document = $this->documentAs('user', '/securedWidgets/1?include=adminTags');
        $this->assertWithheld($this->relationshipOf($document['data'] ?? null, 'adminTags'));
        self::assertSame([], $this->includedOf($document));

        $admin = $this->documentAs('admin', '/securedWidgets/1?include=adminTags');
        $relationship = $this->relationshipOf($admin['data'] ?? null, 'adminTags');
        $data = $relationship['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(1, $data);
        self::assertSame(['type' => 'openWidgets', 'id' => '1'], \array_intersect_key((array) $data[0], ['type' => true, 'id' => true]));
        self::assertSame([['openWidgets', '1']], $this->includedOf($admin));
    }

    #[Test]
    public function a_flattened_write_onto_a_row_the_same_body_links_needs_that_rows_update_gate(): void
    {
        $response = $this->handle('/ownedNotes/1', 'PATCH', [
            'data' => [
                'type' => 'ownedNotes',
                'id' => '1',
                'attributes' => ['folderOwner' => 'ada'],
                'relationships' => ['folder' => ['data' => ['type' => 'ownedFolders', 'id' => '2']]],
            ],
        ], ['HTTP_AUTHORIZATION' => 'Bearer ada']);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        $this->assertStored(note: 1, folder: 1, folderOwners: [1 => 'ada', 2 => 'grace', 3 => 'ada']);
    }

    #[Test]
    public function a_flattened_write_onto_the_currently_linked_row_needs_that_rows_update_gate(): void
    {
        $response = $this->handle('/ownedNotes/2', 'PATCH', [
            'data' => ['type' => 'ownedNotes', 'id' => '2', 'attributes' => ['folderOwner' => 'ada']],
        ], ['HTTP_AUTHORIZATION' => 'Bearer ada']);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        $this->assertStored(note: 2, folder: 2, folderOwners: [1 => 'ada', 2 => 'grace', 3 => 'ada']);
    }

    #[Test]
    public function a_flattened_write_onto_a_row_the_caller_may_update_is_applied(): void
    {
        $response = $this->handle('/ownedNotes/1', 'PATCH', [
            'data' => [
                'type' => 'ownedNotes',
                'id' => '1',
                'attributes' => ['folderOwner' => 'ada'],
                'relationships' => ['folder' => ['data' => ['type' => 'ownedFolders', 'id' => '3']]],
            ],
        ], ['HTTP_AUTHORIZATION' => 'Bearer ada']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertStored(note: 1, folder: 3, folderOwners: [1 => 'ada', 2 => 'grace', 3 => 'ada']);
    }

    /**
     * @param array<int, string> $folderOwners
     */
    private function assertStored(int $note, int $folder, array $folderOwners): void
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        $stored = $entityManager->find(OwnedNoteEntity::class, $note);
        self::assertInstanceOf(OwnedNoteEntity::class, $stored);
        self::assertSame($folder, $stored->folder?->id);

        foreach ($folderOwners as $id => $owner) {
            $row = $entityManager->find(OwnedFolderEntity::class, $id);
            self::assertInstanceOf(OwnedFolderEntity::class, $row);
            self::assertSame($owner, $row->owner, 'folder ' . $id);
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
