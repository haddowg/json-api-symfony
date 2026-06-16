<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures\Seed;
use haddowg\JsonApiBundle\Tests\Functional\JsonApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The optional opis structural linter (`json_api.schema_validation: true`,
 * bundle ADR 0013) end to end over the example app: a write body is validated
 * against the JSON:API JSON Schema before dispatch, so a structurally malformed
 * document is rejected with `400` — a layer distinct from the Symfony Validator
 * bridge's semantic `422` (do the *values* satisfy the constraints?). The linter
 * answers the prior question: is this a well-formed JSON:API document at all?
 *
 * This suite boots the {@see SchemaValidationKernel} variant (the example app with
 * the toggle on). The `400` is attributable to the linter precisely because the
 * same disallowed member, written through any linter-off kernel (the shipped
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\MusicCatalogKernel} every other
 * suite boots), is silently ignored by the hydrator and the write succeeds with
 * `201` — the JSON:API schema is never consulted there.
 *
 * It does not extend {@see MusicCatalogKernelTestCase} (which hard-names the shipped
 * kernel), so it repeats the schema-create + seed `afterBoot` here against the
 * variant kernel's in-memory database.
 */
final class SchemaValidationTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return SchemaValidationKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        Seed::into($entityManager);
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function aResourceObjectWithADisallowedMemberIsRejectedWith400(): void
    {
        // `bogus` is not an allowed resource-object member; the JSON:API schema
        // rejects it. Core's lightweight top-level-member check does not, and the
        // hydrator would silently ignore it — so this is attributable to the linter.
        $response = $this->handle('/playlists', 'POST', [
            'data' => [
                'type' => 'playlists',
                'id' => '00000000-0000-4000-8000-0000000000a1',
                'attributes' => ['title' => 'A Valid Playlist', 'public' => true],
                'bogus' => 'nope',
            ],
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function aStructurallyValidDocumentPassesTheLinter(): void
    {
        $response = $this->handle('/playlists', 'POST', [
            'data' => [
                'type' => 'playlists',
                'id' => '00000000-0000-4000-8000-0000000000a2',
                'attributes' => ['title' => 'A Valid Playlist', 'public' => true],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
    }
}
