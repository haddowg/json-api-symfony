<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A create that sends only the required members stores the entity's defaults for
 * the rest. The Doctrine persister builds new entities without running their
 * constructors, so these defaults live on the property declarations.
 */
final class MinimalCreateTest extends MusicCatalogKernelTestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>, array<string, mixed>}>
     */
    private static function minimalBodies(): iterable
    {
        yield 'tracks' => [
            '/tracks',
            ['type' => 'tracks', 'attributes' => ['title' => 'Minimal', 'trackNumber' => 1, 'genres' => ['rock']]],
            ['durationSeconds' => 0, 'explicit' => false, 'previewOffset' => null],
        ];
        yield 'albums' => [
            '/albums',
            ['type' => 'albums', 'attributes' => ['title' => 'Minimal']],
            ['explicit' => false, 'status' => 'released', 'releaseInfo' => null],
        ];
        yield 'artists' => [
            '/artists',
            ['type' => 'artists', 'attributes' => ['name' => 'Minimal']],
            ['slug' => '', 'trackCount' => 0, 'website' => null],
        ];
        yield 'playlists' => [
            '/playlists',
            ['type' => 'playlists', 'id' => '00000000-0000-4000-8000-0000000000fe', 'attributes' => ['title' => 'Minimal']],
            ['slug' => 'minimal', 'public' => false],
        ];
        yield 'products' => ['/products', ['type' => 'products', 'attributes' => ['name' => 'Minimal']], []];
        yield 'favorites' => ['/favorites', ['type' => 'favorites'], ['favoritedAt' => null]];
        yield 'releases' => ['/releases', ['type' => 'releases', 'attributes' => ['catalogNumber' => 'MIN-1']], ['format' => null, 'dimensions' => null]];
        yield 'devices' => ['/devices', ['type' => 'devices', 'attributes' => ['label' => 'Minimal']], []];
        yield 'genres' => ['/genres', ['type' => 'genres', 'id' => 'minimal', 'attributes' => ['name' => 'Minimal']], []];
        yield 'users' => ['/admin/users', ['type' => 'users', 'attributes' => ['email' => 'minimal@example.com', 'displayName' => 'Minimal', 'password' => 'longpassword1']], ['preferences' => [], 'birthDate' => null]];
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function aCreateWithOnlyRequiredMembersStoresTheDefaults(): void
    {
        foreach (self::minimalBodies() as $type => [$path, $data, $defaults]) {
            $response = $this->handle($path, 'POST', ['data' => $data]);

            self::assertSame(201, $response->getStatusCode(), $type . ': ' . $response->getContent());
            $data = $this->decode($response)['data'] ?? null;
            self::assertIsArray($data, $type);
            $attributes = $data['attributes'] ?? [];
            self::assertIsArray($attributes, $type);
            foreach ($defaults as $name => $value) {
                self::assertArrayHasKey($name, $attributes, $type . '.' . $name);
                self::assertSame($value, $attributes[$name], $type . '.' . $name);
            }
        }
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function aTrackWithoutGenresIsRejected(): void
    {
        // An empty list would break the field's own minItems(1), so genres has no
        // default and a create has to send it.
        $response = $this->handle('/tracks', 'POST', [
            'data' => ['type' => 'tracks', 'attributes' => ['title' => 'Minimal', 'trackNumber' => 1]],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertCount(1, $errors);
        self::assertIsArray($errors[0]);
        $source = $errors[0]['source'] ?? null;
        self::assertIsArray($source);
        self::assertSame('/data/attributes/genres', $source['pointer'] ?? null);
    }
}
