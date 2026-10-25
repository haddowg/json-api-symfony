<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\FolderLabelEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OwnedFolderEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecurityTestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * A folder label's foreign key is NOT NULL, so a label can't be left without a folder:
 * a write that would detach one is refused with a 403 and changes nothing.
 *
 * Seeded: folders 1 and 2 belong to ada; labels 1 and 2 are in folder 1, label 3 in
 * folder 2.
 */
final class DoctrineNotNullForeignKeyTest extends JsonApiFunctionalTestCase
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

        $one = new OwnedFolderEntity(1, 'ada');
        $two = new OwnedFolderEntity(2, 'ada');
        $entityManager->persist($one);
        $entityManager->persist($two);
        $entityManager->persist(new FolderLabelEntity(1, 'red', $one));
        $entityManager->persist(new FolderLabelEntity(2, 'blue', $one));
        $entityManager->persist(new FolderLabelEntity(3, 'green', $two));
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function removing_a_member_whose_foreign_key_is_not_null_is_refused(): void
    {
        $response = $this->asAda('/ownedFolders/1/relationships/labels', 'DELETE', ['data' => [['type' => 'folderLabels', 'id' => '1']]]);

        $this->assertRefused($response, 'REMOVAL_PROHIBITED');
        $this->assertLabelsOf(1, [1, 2]);
    }

    #[Test]
    public function a_replacement_that_drops_a_member_whose_foreign_key_is_not_null_is_refused(): void
    {
        $response = $this->asAda('/ownedFolders/1/relationships/labels', 'PATCH', ['data' => [['type' => 'folderLabels', 'id' => '1']]]);

        $this->assertRefused($response, 'FULL_REPLACEMENT_PROHIBITED');
        $this->assertLabelsOf(1, [1, 2]);
    }

    #[Test]
    public function a_whole_resource_update_that_drops_such_a_member_is_refused(): void
    {
        $response = $this->asAda('/ownedFolders/1', 'PATCH', ['data' => [
            'type' => 'ownedFolders',
            'id' => '1',
            'relationships' => ['labels' => ['data' => []]],
        ]]);

        $this->assertRefused($response, 'FULL_REPLACEMENT_PROHIBITED');
        $this->assertLabelsOf(1, [1, 2]);
    }

    #[Test]
    public function a_replacement_that_keeps_every_member_and_moves_one_in_is_applied(): void
    {
        $response = $this->asAda('/ownedFolders/1/relationships/labels', 'PATCH', ['data' => [
            ['type' => 'folderLabels', 'id' => '1'],
            ['type' => 'folderLabels', 'id' => '2'],
            ['type' => 'folderLabels', 'id' => '3'],
        ]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertLabelsOf(1, [1, 2, 3]);
        $this->assertLabelsOf(2, []);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function asAda(string $path, string $method, array $body): Response
    {
        return $this->handle($path, $method, $body, ['HTTP_AUTHORIZATION' => 'Bearer ada']);
    }

    private function assertRefused(Response $response, string $code): void
    {
        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        self::assertSame($code, $first['code'] ?? null);
    }

    /**
     * @param list<int> $expected
     */
    private function assertLabelsOf(int $folder, array $expected): void
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        $ids = [];
        foreach ($entityManager->getRepository(FolderLabelEntity::class)->findAll() as $label) {
            if ($label->folder?->id === $folder) {
                $ids[] = (int) $label->id;
            }
        }
        \sort($ids);
        self::assertSame($expected, $ids);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
