<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The write-only attribute witness (Tier-0 G18, core ADR 0060). `users.password` is
 * declared `Str::make('password')->writeOnly()->minLength(8)->requiredOnCreate()` —
 * the exact inverse of `readOnly()`: a credential a client SETS on write but the
 * server NEVER echoes back.
 *
 * This suite proves the whole contract end to end against the Doctrine-backed
 * example app: the value is **accepted and stored** on both create and update
 * (verified against the persisted {@see User} entity, not just the response), it is
 * **still validated** on write (required on create, min 8), and it is **absent from
 * every read representation** — a single read, a collection read, every resource of
 * a compound (`?include`) document, and even a sparse fieldset that explicitly names
 * it (which cannot resurrect it). `passwordConfirm` (computed AND write-only) is
 * likewise never rendered.
 *
 * `users` is the admin-only resource, so every endpoint is reached under `/admin`.
 */
#[Group('spec:crud')]
final class WriteOnlyAttributeTest extends MusicCatalogKernelTestCase
{
    #[Test]
    #[Group('spec:creating-resources')]
    public function creatingAcceptsAndStoresTheWriteOnlyPasswordButNeverRendersIt(): void
    {
        $browser = $this->browser();

        $browser->post('/admin/users', [
            'data' => [
                'type' => 'users',
                'attributes' => [
                    'email' => 'grace@example.com',
                    'displayName' => 'Grace',
                    'preferences' => ['theme' => 'light'],
                    'password' => 'longpassword1',
                ],
            ],
        ])->assertCreated();

        $data = $this->decode($browser->getResponse())['data'] ?? null;
        self::assertIsArray($data);

        // The credential is accepted but NEVER echoed in the create response.
        $attributes = $this->attributesOf($data);
        self::assertArrayHasKey('displayName', $attributes);
        self::assertArrayNotHasKey('password', $attributes);
        self::assertArrayNotHasKey('passwordConfirm', $attributes);

        // ...yet it WAS stored: the persisted entity carries the value.
        $id = $data['id'] ?? null;
        self::assertIsString($id);
        self::assertSame('longpassword1', $this->storedPassword((int) $id));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function updatingAcceptsAndStoresTheWriteOnlyPasswordButNeverRendersIt(): void
    {
        // User 1 is seeded with a null password; a PATCH sets it.
        $browser = $this->browser();

        $browser->patch('/admin/users/1', [
            'data' => [
                'type' => 'users',
                'id' => '1',
                'attributes' => ['password' => 'newsecret9'],
            ],
        ])->assertFetchedOne();

        $attributes = $this->attributesOf($this->primaryData($browser->getResponse()));
        self::assertArrayNotHasKey('password', $attributes);
        self::assertArrayNotHasKey('passwordConfirm', $attributes);

        // The update reached the entity: the new credential is stored.
        self::assertSame('newsecret9', $this->storedPassword(1));
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function creatingRequiresTheWriteOnlyPasswordAndStillValidatesIt(): void
    {
        // requiredOnCreate(): omitting `password` on POST is a 422 at its pointer —
        // a write-only field is still validated, it is merely never rendered.
        $missing = $this->handle('/admin/users', 'POST', [
            'data' => ['type' => 'users', 'attributes' => [
                'email' => 'omitted@example.com', 'displayName' => 'No Password',
            ]],
        ]);

        self::assertSame(422, $missing->getStatusCode());
        self::assertContains('/data/attributes/password', $this->pointers($missing));

        // minLength(8): a supplied-but-too-short value is also a 422 at its pointer.
        $tooShort = $this->handle('/admin/users', 'POST', [
            'data' => ['type' => 'users', 'attributes' => [
                'email' => 'short@example.com', 'displayName' => 'Too Short', 'password' => 'tiny',
            ]],
        ]);

        self::assertSame(422, $tooShort->getStatusCode());
        self::assertContains('/data/attributes/password', $this->pointers($tooShort));
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function aSingleReadNeverRendersTheWriteOnlyAttributes(): void
    {
        $attributes = $this->attributesOf($this->primaryData($this->handle('/admin/users/1')));

        self::assertArrayHasKey('email', $attributes);
        self::assertArrayNotHasKey('password', $attributes);
        self::assertArrayNotHasKey('passwordConfirm', $attributes);
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function aCollectionReadNeverRendersTheWriteOnlyAttributes(): void
    {
        $collection = $this->decode($this->handle('/admin/users'))['data'] ?? null;
        self::assertIsArray($collection);
        self::assertNotEmpty($collection);

        foreach ($collection as $resource) {
            self::assertIsArray($resource);
            $attributes = $this->attributesOf($resource);
            self::assertArrayNotHasKey('password', $attributes);
            self::assertArrayNotHasKey('passwordConfirm', $attributes);
        }
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aWriteOnlyAttributeIsAbsentAcrossAWholeCompoundDocument(): void
    {
        // The skip lives in the serializer's attribute render, the one path that
        // produces every resource object — primary OR included. A compound read of
        // user 1 with its playlists must therefore carry no write-only attribute on
        // ANY resource anywhere in the document (the `users` member appears as the
        // primary `data`; the same render governs every `included` member too).
        $document = $this->decode($this->handle('/admin/users/1?include=playlists'));

        $resources = [];
        $primary = $document['data'] ?? null;
        if (\is_array($primary)) {
            $resources[] = $primary;
        }
        $included = $document['included'] ?? [];
        self::assertIsArray($included);
        foreach ($included as $member) {
            if (\is_array($member)) {
                $resources[] = $member;
            }
        }

        self::assertNotEmpty($resources);
        foreach ($resources as $resource) {
            $attributes = $this->attributesOf($resource);
            self::assertArrayNotHasKey('password', $attributes);
            self::assertArrayNotHasKey('passwordConfirm', $attributes);
        }
    }

    #[Test]
    #[Group('spec:fetching-sparse-fieldsets')]
    public function aSparseFieldsetNamingTheWriteOnlyAttributeCannotResurrectIt(): void
    {
        // A write-only field is skipped BEFORE sparse-fieldset filtering, so naming it
        // explicitly yields an empty attributes object rather than the credential.
        $attributes = $this->attributesOf(
            $this->primaryData($this->handle('/admin/users/1?fields[users]=password')),
        );

        self::assertArrayNotHasKey('password', $attributes);
        self::assertSame([], $attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function primaryData(Response $response): array
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    private function attributesOf(array $resource): array
    {
        $attributes = $resource['attributes'] ?? [];
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }

    /**
     * The `source.pointer` of every error in the response document.
     *
     * @return list<string>
     */
    private function pointers(Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);

        $pointers = [];
        foreach ($errors as $error) {
            self::assertIsArray($error);
            $source = $error['source'] ?? null;
            if (!\is_array($source)) {
                continue;
            }
            $pointer = $source['pointer'] ?? null;
            if (\is_string($pointer)) {
                $pointers[] = $pointer;
            }
        }

        return $pointers;
    }

    /**
     * The credential as actually persisted, read straight off the {@see User} entity
     * (the EntityManager is cleared first so a managed identity-map copy cannot mask
     * the stored value).
     */
    private function storedPassword(int $id): ?string
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entityManager->clear();
        $user = $entityManager->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);

        return $user->password;
    }
}
