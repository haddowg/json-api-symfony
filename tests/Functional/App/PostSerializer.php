<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\RendersRelationsTrait;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiBundle\Operation\Operation;
use haddowg\JsonApiBundle\Server\RelationsRegistry;

/**
 * A standalone serializer for the resource-less `posts` type (ADR 0026): it renders
 * relationships without an {@see \haddowg\JsonApi\Resource\AbstractResource} by
 * opting into the resolver via {@see SerializerResolverAwareInterface} and building
 * the relationship callables from the type's standalone relations
 * ({@see RelationsRegistry}) through core's {@see RendersRelationsTrait}. Only
 * {@see Operation::FetchOne} is exposed, so just `GET /posts/{id}` is routed for the
 * primary op; the relationship routes ride the type's declared relations.
 */
#[AsJsonApiSerializer(type: 'posts', operations: [Operation::FetchOne])]
final class PostSerializer implements SerializerInterface, SerializerResolverAwareInterface
{
    use RendersRelationsTrait;

    private ?SerializerResolverInterface $resolver = null;

    public function __construct(private readonly RelationsRegistry $relations) {}

    public function setSerializerResolver(SerializerResolverInterface $resolver): void
    {
        $this->resolver = $resolver;
    }

    public function getType(mixed $object): string
    {
        return 'posts';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Post);

        return $object->id;
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
            'title' => static function (mixed $model, JsonApiRequestInterface $request, string $name): string {
                \assert($model instanceof Post);

                return $model->title;
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

        return self::relationshipCallables($this->relations->relationsFor('posts') ?? [], $this->resolver);
    }
}
