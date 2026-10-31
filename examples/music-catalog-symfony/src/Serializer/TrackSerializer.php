<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApi\Resource\ProvidesOutputTimezoneInterface;
use haddowg\JsonApi\Resource\RendersRelationsTrait;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Serializer\RendersDeclaredLinksInterface;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Track;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Resource\TrackResource;

/**
 * A hand-written read serializer for the `tracks` type — the serializer-override
 * escape hatch, registered on {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Resource\TrackResource}
 * via `#[AsJsonApiResource(serializer: TrackSerializer::class)]` (bundle ADR 0024).
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
 *
 * It renders the resource's declared `preview` link too, through
 * {@see RendersDeclaredLinksInterface}. A serializer that replaces a resource renders
 * only what it implements, so without this the link the OpenAPI document describes
 * would never reach the wire.
 */
final class TrackSerializer extends AbstractSerializer implements SerializerResolverAwareInterface, RendersDeclaredLinksInterface
{
    use RendersRelationsTrait;

    private ?SerializerResolverInterface $serializerResolver = null;

    private ?DeclaredLink $previewLink = null;

    public function __construct(private readonly string $catalogTag) {}

    public function setSerializerResolver(SerializerResolverInterface $resolver): void
    {
        $this->serializerResolver = $resolver;
    }

    public function serializerResolver(): ?SerializerResolverInterface
    {
        return $this->serializerResolver;
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

        // The store-provided id is a database-assigned integer; the JSON:API id is its
        // string form.
        return (string) $object->id;
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

    public function renderDeclaredLinks(mixed $object, JsonApiRequestInterface $request): array
    {
        $this->previewLink ??= TrackResource::previewLink()->build();
        $outputTimezone = $this->serializerResolver instanceof ProvidesOutputTimezoneInterface
            ? $this->serializerResolver->outputTimezone()
            : null;

        $preview = $this->previewLink->resolve($object, $request, $outputTimezone);

        return $preview !== null ? ['preview' => $preview] : [];
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
                => $track instanceof Track && $request->getAttribute('nowPlayingTrackId') === (string) $track->id;
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
            BelongsTo::make('album', 'albums')->build(),
            BelongsToMany::make('playlists', 'playlists')
                ->fields(
                    Integer::make('position')->min(1)->build(),
                    DateTime::make('addedAt')->readOnly()->build(),
                )
                ->cannotReplace()
                // A to-many is lazy by default (core ADR 0067); withData() opts this
                // one back to EAGER so its linkage renders on a track read — the
                // deliberate contrast with AlbumResource's lazy `tracks`.
                ->withData()
                ->build(),
        ];
    }
}
