<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\IdSource\IdSourceTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Id source/policy witnesses (bundle ADR 0039) end-to-end over the Doctrine
 * persister: each new behaviour the model expresses proven by a real create through the
 * `-128` Doctrine fallback persister against an in-memory SQLite database.
 *
 *  - store-provided (the default): the DB assigns the id, the `201` round-trips it;
 *  - `allowClientId()`: a client id is honoured when supplied, minted when omitted;
 *  - `requireClientId()`: a create without an id is a `403`;
 *  - `generated()`: `uuid()` mints a v4 UUID, `ulid()` a Crockford-base32 ULID;
 *  - `generateUsing()`: a closure mints the storage key;
 *  - linkage-format validation: a relationship linkage id that violates the related
 *    type's id format is a `422` pointed at the linkage; a valid one passes.
 */
final class DoctrineIdSourceTest extends JsonApiFunctionalTestCase
{
    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    #[Test]
    #[Group('spec:crud')]
    public function aStoreProvidedCreateLetsTheDatabaseAssignTheId(): void
    {
        $response = $this->handle('/counters', 'POST', [
            'data' => ['type' => 'counters', 'attributes' => ['label' => 'first']],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $id = $this->idOf($response);
        // The DB assigned an auto-increment integer the create did not carry.
        self::assertSame('1', $id);
        self::assertSame('https://example.test/counters/' . $id, $response->headers->get('Location'));

        // The assigned id round-trips on a follow-up read.
        $fetched = $this->handle('/counters/' . $id);
        self::assertSame(200, $fetched->getStatusCode());
        self::assertSame('first', $this->attributesOf($fetched)['label'] ?? null);

        // A second create increments — the store, not core, owns the sequence.
        $second = $this->handle('/counters', 'POST', [
            'data' => ['type' => 'counters', 'attributes' => ['label' => 'second']],
        ]);
        self::assertSame('2', $this->idOf($second));
    }

    #[Test]
    #[Group('spec:crud')]
    public function anAllowClientIdCreateMintsAUuidWhenNoneIsSupplied(): void
    {
        $response = $this->handle('/markers', 'POST', [
            'data' => ['type' => 'markers', 'attributes' => ['name' => 'minted']],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $id = $this->idOf($response);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    #[Test]
    #[Group('spec:crud')]
    public function anAllowClientIdCreateHonoursAWellFormedClientId(): void
    {
        $clientId = '11111111-1111-4111-8111-111111111111';
        $response = $this->handle('/markers', 'POST', [
            'data' => ['type' => 'markers', 'id' => $clientId, 'attributes' => ['name' => 'supplied']],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($clientId, $this->idOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function anAllowClientIdCreateRejectsAMalformedClientId(): void
    {
        $response = $this->handle('/markers', 'POST', [
            'data' => ['type' => 'markers', 'id' => 'not-a-uuid', 'attributes' => ['name' => 'bad']],
        ]);

        // The client id violates the markers uuid() format — the validator bridge 422s.
        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:crud')]
    public function aRequireClientIdCreateWithoutAnIdIs403(): void
    {
        $response = $this->handle('/tokens', 'POST', [
            'data' => ['type' => 'tokens', 'attributes' => ['value' => 'v']],
        ]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/data/id', $this->firstErrorPointer($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aRequireClientIdCreateWithAnIdUsesItAsTheKey(): void
    {
        $response = $this->handle('/tokens', 'POST', [
            'data' => ['type' => 'tokens', 'id' => 'natural-key', 'attributes' => ['value' => 'v']],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('natural-key', $this->idOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aGeneratedUlidCreateMintsAUlid(): void
    {
        $response = $this->handle('/badges', 'POST', [
            'data' => ['type' => 'badges', 'attributes' => ['name' => 'b']],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertMatchesRegularExpression(
            '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/',
            $this->idOf($response),
        );
    }

    #[Test]
    #[Group('spec:crud')]
    public function aForbiddenTypeRejectsAnyClientIdWith403RegardlessOfItsFormat(): void
    {
        // Badges (`ulid()->generated()`) forbid a client id yet declare a format. A
        // supplied id — well-formed or not — is core's `403`, never a format `422`:
        // the format is irrelevant when the type rejects any client id outright.
        $malformed = $this->handle('/badges', 'POST', [
            'data' => ['type' => 'badges', 'id' => 'not-a-ulid', 'attributes' => ['name' => 'b']],
        ]);
        self::assertSame(403, $malformed->getStatusCode(), (string) $malformed->getContent());
        self::assertSame('CLIENT_GENERATED_ID_NOT_SUPPORTED', $this->firstErrorCode($malformed));

        $wellFormed = $this->handle('/badges', 'POST', [
            'data' => ['type' => 'badges', 'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'attributes' => ['name' => 'b']],
        ]);
        self::assertSame(403, $wellFormed->getStatusCode(), (string) $wellFormed->getContent());
        self::assertSame('CLIENT_GENERATED_ID_NOT_SUPPORTED', $this->firstErrorCode($wellFormed));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aGenerateUsingCreateMintsTheClosureKey(): void
    {
        $response = $this->handle('/slugs', 'POST', [
            'data' => ['type' => 'slugs', 'attributes' => ['title' => 't']],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertMatchesRegularExpression('/^slug-[0-9a-f]{8}$/', $this->idOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aLinkageIdViolatingTheRelatedFormatIs422AtTheLinkage(): void
    {
        $response = $this->handle('/counters', 'POST', [
            'data' => [
                'type' => 'counters',
                'attributes' => ['label' => 'linked'],
                'relationships' => [
                    'marker' => ['data' => ['type' => 'markers', 'id' => 'not-a-uuid']],
                ],
            ],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/data/relationships/marker/data/id', $this->firstErrorPointer($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aWellFormedLinkageIdPassesFormatValidation(): void
    {
        // Seed a marker the linkage can reference.
        $marker = $this->handle('/markers', 'POST', [
            'data' => ['type' => 'markers', 'attributes' => ['name' => 'target']],
        ]);
        $markerId = $this->idOf($marker);

        $response = $this->handle('/counters', 'POST', [
            'data' => [
                'type' => 'counters',
                'attributes' => ['label' => 'linked'],
                'relationships' => [
                    'marker' => ['data' => ['type' => 'markers', 'id' => $markerId]],
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:crud')]
    public function aMalformedLinkageIdOnARelationshipEndpointIs422AtTheLinkage(): void
    {
        // Seed a counter to mutate the relationship of.
        $counter = $this->handle('/counters', 'POST', [
            'data' => ['type' => 'counters', 'attributes' => ['label' => 'rel']],
        ]);
        $counterId = $this->idOf($counter);

        // A relationship-mutation endpoint linkage id is format-validated against the
        // related type's id format exactly as a whole-resource-write linkage is.
        $response = $this->handle('/counters/' . $counterId . '/relationships/marker', 'PATCH', [
            'data' => ['type' => 'markers', 'id' => 'not-a-uuid'],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/data/id', $this->firstErrorPointer($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aWellFormedLinkageIdOnARelationshipEndpointPasses(): void
    {
        $marker = $this->handle('/markers', 'POST', [
            'data' => ['type' => 'markers', 'attributes' => ['name' => 'target']],
        ]);
        $markerId = $this->idOf($marker);

        $counter = $this->handle('/counters', 'POST', [
            'data' => ['type' => 'counters', 'attributes' => ['label' => 'rel']],
        ]);
        $counterId = $this->idOf($counter);

        $response = $this->handle('/counters/' . $counterId . '/relationships/marker', 'PATCH', [
            'data' => ['type' => 'markers', 'id' => $markerId],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    protected static function getKernelClass(): string
    {
        return IdSourceTestKernel::class;
    }

    private function idOf(Response $response): string
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);
        self::assertNotSame('', $id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesOf(Response $response): array
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }

    private function firstErrorPointer(Response $response): ?string
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        $source = $first['source'] ?? null;
        self::assertIsArray($source);
        $pointer = $source['pointer'] ?? null;

        return \is_string($pointer) ? $pointer : null;
    }

    private function firstErrorCode(Response $response): ?string
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        $code = $first['code'] ?? null;

        return \is_string($code) ? $code : null;
    }
}
