<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\RendersRelationsTrait;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiBundle\Operation\Operation;
use haddowg\JsonApiBundle\Server\RelationsRegistry;
use haddowg\JsonApiBundle\Server\ServerName;

/**
 * The standalone `notes` serializer on both servers. It renders the relations the
 * server it serves declares for `notes`.
 */
#[AsJsonApiSerializer(type: 'notes', server: ['default', 'v5'], operations: [Operation::FetchOne])]
final class NoteSerializer implements SerializerInterface, SerializerResolverAwareInterface
{
    use RendersRelationsTrait;

    private ?SerializerResolverInterface $resolver = null;

    public function __construct(private readonly RelationsRegistry $relations) {}

    public function setSerializerResolver(SerializerResolverInterface $resolver): void
    {
        $this->resolver = $resolver;
    }

    public function serializerResolver(): ?SerializerResolverInterface
    {
        return $this->resolver;
    }

    public function getType(mixed $object): string
    {
        return 'notes';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Note);

        return (string) $object->id;
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return [
            'name' => static function (mixed $model): string {
                \assert($model instanceof Note);

                return $model->name;
            },
        ];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        if ($this->resolver === null) {
            return [];
        }

        return self::relationshipCallables($this->relations->relationsFor('notes', ServerName::of($this->resolver)) ?? [], $this->resolver);
    }
}
