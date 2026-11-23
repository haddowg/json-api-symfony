<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OptionNamedFields\PresetFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attributes and object members named `groups`, `fields` or `payload`, the names of
 * Symfony's Collection constraint options, validate and write like any other, on every
 * supported validator version. Run against both providers.
 */
abstract class OptionNamedFieldsConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const array OPTIONS = ['groups' => 'admins', 'fields' => 'name', 'payload' => 'raw', 'allowMissingFields' => 'no'];

    #[Test]
    #[Group('spec:crud')]
    public function aCreateWritesFieldsNamedLikeCollectionOptions(): void
    {
        $created = $this->create(['groups' => 'admins', 'fields' => 'name', 'payload' => 'raw', 'options' => self::OPTIONS]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());

        $this->detachPersistedState();
        $attributes = $this->attributesOf($this->handle('/presets/' . $this->createdId($created)));
        self::assertSame(
            ['groups' => 'admins', 'fields' => 'name', 'payload' => 'raw', 'options' => self::OPTIONS],
            $attributes,
        );
    }

    #[Test]
    #[Group('spec:crud')]
    public function anUpdateWritesFieldsNamedLikeCollectionOptions(): void
    {
        $response = $this->handle('/presets/1', 'PATCH', [
            'data' => ['type' => 'presets', 'id' => '1', 'attributes' => ['groups' => 'editors', 'options' => ['groups' => 'editors']]],
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->detachPersistedState();
        $attributes = $this->attributesOf($this->handle('/presets/1'));
        self::assertSame('editors', $attributes['groups'] ?? null);
        self::assertSame('seed', $attributes['fields'] ?? null);
        self::assertSame(['groups' => 'editors'] + PresetFactory::SEED_OPTIONS, $attributes['options'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function theirRulesAreEnforced(): void
    {
        $long = \str_repeat('x', 11);
        $response = $this->create([
            'groups' => $long,
            'fields' => $long,
            'payload' => $long,
            'options' => ['groups' => $long, 'fields' => $long, 'payload' => $long],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertEqualsCanonicalizing(
            [
                '/data/attributes/groups',
                '/data/attributes/fields',
                '/data/attributes/payload',
                '/data/attributes/options/groups',
                '/data/attributes/options/fields',
                '/data/attributes/options/payload',
            ],
            $this->pointersOf($response),
        );
    }

    #[Test]
    #[Group('spec:crud')]
    public function aMissingRequiredOneIsReported(): void
    {
        $response = $this->create(['options' => ['fields' => 'name']]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertEqualsCanonicalizing(['/data/attributes/groups', '/data/attributes/options/groups'], $this->pointersOf($response));
    }

    /**
     * Detaches persisted state before a re-read, so the read decodes what the store
     * holds. The Doctrine provider overrides this to clear the identity map.
     */
    protected function detachPersistedState(): void {}

    /**
     * @param array<string, mixed> $attributes
     */
    private function create(array $attributes): Response
    {
        return $this->handle('/presets', 'POST', ['data' => ['type' => 'presets', 'attributes' => $attributes]]);
    }

    private function createdId(Response $response): string
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesOf(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);

        return $attributes;
    }

    /**
     * @return list<mixed>
     */
    private function pointersOf(Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);

        return \array_values(\array_map(
            static fn(mixed $error): mixed => \is_array($error) && \is_array($error['source'] ?? null) ? $error['source']['pointer'] ?? null : null,
            $errors,
        ));
    }
}
