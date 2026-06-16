<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\RendersRelationsTrait;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Track;

/**
 * A hand-written read serializer for the `tracks` type — the serializer-override
 * escape hatch, registered on {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Resource\TrackResource}
 * via `#[AsJsonApiResource(serializer: TrackSerializer::class)]` (bundle ADR 0023).
 * It wins for serialization while the resource still hydrates writes.
 *
 * Re-themed from core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Serializer/TrackSerializer.php TrackSerializer},
 * with one Symfony-specific addition: it takes a **bound constructor argument**
 * (`$catalogTag`, surfaced in `meta`), so a successful read proves the bundle
 * resolved it through the container *with* its dependency — a plain `new` (core's
 * registration model) could not supply it. It opts into
 * {@see SerializerResolverAwareInterface} so the registry injects the resolver
 * after construction, letting {@see RendersRelationsTrait} render the `album` and
 * `playlists` relationships through the same relation declarations the resource
 * uses.
 */
final class TrackSerializer extends AbstractSerializer implements SerializerResolverAwareInterface
{
    use RendersRelationsTrait;

    private ?SerializerResolverInterface $serializerResolver = null;

    public function __construct(private readonly string $catalogTag) {}

    public function setSerializerResolver(SerializerResolverInterface $resolver): void
    {
        $this->serializerResolver = $resolver;
    }

    public function getType(mixed $object): string
    {
        // Object-aware so a polymorphic resolver probing this serializer with a
        // foreign member does not have it falsely claim a `tracks` type.
        return $object instanceof Track ? 'tracks' : '';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Track);

        return $object->id;
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        // The bound constructor dependency surfaced on the wire — the DI-resolution
        // witness.
        return ['served_by' => $this->catalogTag];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        $attributes = [
            'title' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): string
                => $track instanceof Track ? $track->title : '',
            'trackNumber' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): int
                => $track instanceof Track ? $track->trackNumber : 0,
            'durationSeconds' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): int
                => $track instanceof Track ? $track->length_seconds : 0,
            'explicit' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): bool
                => $track instanceof Track && $track->explicit,
            'genres' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): array
                => $track instanceof Track ? $track->genres : [],
            'previewOffset' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): ?string
                => $track instanceof Track ? $track->previewOffset : null,
            // Computed across two columns purely on read.
            'displayTitle' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): string
                => $track instanceof Track ? \sprintf('%d. %s', $track->trackNumber, $track->title) : '',
        ];

        // Request-aware: the `nowPlaying` attribute exists ONLY for an authenticated
        // user. The attribute *set* is request-dependent — anonymous responses omit it.
        if ($request->getAttribute('user') !== null) {
            $attributes['nowPlaying'] = static fn(mixed $track, JsonApiRequestInterface $request, string $field): bool
                => $track instanceof Track && $request->getAttribute('nowPlayingTrackId') === $track->id;
        }

        return $attributes;
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        $resolver = $this->serializerResolver;
        if ($resolver === null) {
            return [];
        }

        return self::relationshipCallables($this->relations(), $resolver);
    }

    /**
     * The relation fields this serializer renders — the same declarations the
     * resource makes for `album` and `playlists`. Built per call (the contract is
     * stateless), so one instance safely serializes many tracks.
     *
     * @return list<RelationInterface>
     */
    private function relations(): array
    {
        return [
            BelongsTo::make('album')->type('albums'),
            BelongsToMany::make('playlists')
                ->type('playlists')
                ->fields(['position' => 'integer', 'addedAt' => 'datetime'])
                ->cannotReplace(),
        ];
    }
}
