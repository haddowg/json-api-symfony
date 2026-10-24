<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OwnedFolderEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OwnedNoteEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecurityTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * Writing the inverse side of a one-to-many (`ownedFolders.notes`) changes each
 * note's `folder`. A note already in another folder may be moved into the caller's
 * folder only when the caller may update that other folder or the note itself, and
 * removing a note that is not in the folder leaves it where it is.
 *
 * Seeded: folders 1 and 3 belong to ada, folder 2 to grace. Notes: 1 (ada, folder 1),
 * 2 (grace, folder 2), 3 (ada, folder 3), 4 (grace, folder 3), 5 (ada, folder 2),
 * 6 (grace, no folder).
 */
final class DoctrineMovedMemberSecurityTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return SecurityTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $folders = [
            1 => new OwnedFolderEntity(1, 'ada'),
            2 => new OwnedFolderEntity(2, 'grace'),
            3 => new OwnedFolderEntity(3, 'ada'),
        ];
        foreach ($folders as $folder) {
            $entityManager->persist($folder);
        }

        foreach ([1 => ['ada', 1], 2 => ['grace', 2], 3 => ['ada', 3], 4 => ['grace', 3], 5 => ['ada', 2], 6 => ['grace', null]] as $id => [$owner, $folder]) {
            $entityManager->persist(new OwnedNoteEntity($id, $owner, $folder !== null ? $folders[$folder] : null));
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function adding_a_note_from_a_folder_the_caller_cannot_update_is_forbidden(): void
    {
        $this->browser()
            ->actingAs('ada')
            ->post('/ownedFolders/1/relationships/notes', ['data' => [['type' => 'ownedNotes', 'id' => '2']]])
            ->getErrors()
            ->assertStatus(403)
            ->assertHasError(status: '403', code: 'ACCESS_DENIED');

        self::assertSame([1 => 1, 2 => 2, 3 => 3, 4 => 3, 5 => 2, 6 => null], $this->noteFolders());
    }

    #[Test]
    public function replacing_with_a_note_from_a_folder_the_caller_cannot_update_is_forbidden(): void
    {
        $this->browser()
            ->actingAs('ada')
            ->patch('/ownedFolders/1/relationships/notes', ['data' => [['type' => 'ownedNotes', 'id' => '1'], ['type' => 'ownedNotes', 'id' => '2']]])
            ->getErrors()
            ->assertStatus(403)
            ->assertHasError(status: '403', code: 'ACCESS_DENIED');

        self::assertSame([1 => 1, 2 => 2, 3 => 3, 4 => 3, 5 => 2, 6 => null], $this->noteFolders());
    }

    #[Test]
    public function an_embedded_write_with_a_note_from_a_folder_the_caller_cannot_update_is_forbidden(): void
    {
        $this->browser()
            ->actingAs('ada')
            ->patch('/ownedFolders/1', [
                'data' => [
                    'type' => 'ownedFolders',
                    'id' => '1',
                    'relationships' => ['notes' => ['data' => [['type' => 'ownedNotes', 'id' => '1'], ['type' => 'ownedNotes', 'id' => '2']]]],
                ],
            ])
            ->getErrors()
            ->assertStatus(403)
            ->assertHasError(status: '403', code: 'ACCESS_DENIED');

        self::assertSame([1 => 1, 2 => 2, 3 => 3, 4 => 3, 5 => 2, 6 => null], $this->noteFolders());
    }

    #[Test]
    public function adding_a_note_without_a_folder_that_the_caller_cannot_update_is_forbidden(): void
    {
        $this->browser()
            ->actingAs('ada')
            ->post('/ownedFolders/1/relationships/notes', ['data' => [['type' => 'ownedNotes', 'id' => '6']]])
            ->getErrors()
            ->assertStatus(403)
            ->assertHasError(status: '403', code: 'ACCESS_DENIED');

        self::assertSame([1 => 1, 2 => 2, 3 => 3, 4 => 3, 5 => 2, 6 => null], $this->noteFolders());
    }

    #[Test]
    public function a_note_may_be_moved_from_another_folder_the_caller_may_update(): void
    {
        $this->browser()
            ->actingAs('ada')
            ->post('/ownedFolders/1/relationships/notes', ['data' => [['type' => 'ownedNotes', 'id' => '4']]])
            ->getDocument()
            ->assertStatus(200);

        self::assertSame([1 => 1, 2 => 2, 3 => 3, 4 => 1, 5 => 2, 6 => null], $this->noteFolders());
    }

    #[Test]
    public function a_note_the_caller_may_update_may_be_moved_out_of_any_folder(): void
    {
        $this->browser()
            ->actingAs('ada')
            ->post('/ownedFolders/1/relationships/notes', ['data' => [['type' => 'ownedNotes', 'id' => '5']]])
            ->getDocument()
            ->assertStatus(200);

        self::assertSame([1 => 1, 2 => 2, 3 => 3, 4 => 3, 5 => 1, 6 => null], $this->noteFolders());
    }

    #[Test]
    public function removing_a_note_that_is_not_in_the_folder_leaves_it_where_it_is(): void
    {
        $this->browser()
            ->actingAs('ada')
            ->delete('/ownedFolders/1/relationships/notes', ['data' => [['type' => 'ownedNotes', 'id' => '2']]])
            ->getDocument()
            ->assertStatus(200);

        self::assertSame([1 => 1, 2 => 2, 3 => 3, 4 => 3, 5 => 2, 6 => null], $this->noteFolders());
    }

    /**
     * Each note's folder id, read fresh from the database.
     *
     * @return array<int, ?int>
     */
    private function noteFolders(): array
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        $folders = [];
        foreach ($entityManager->getRepository(OwnedNoteEntity::class)->findBy([], ['id' => 'ASC']) as $note) {
            \assert($note->id !== null);
            $folders[$note->id] = $note->folder?->id;
        }

        return $folders;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
