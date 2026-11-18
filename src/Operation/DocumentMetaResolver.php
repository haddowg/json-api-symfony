<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Operation;

use haddowg\JsonApi\OpenApi\Metadata\ActionCreated;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Resource\DocumentMeta;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\DataProvider\Polymorphism;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;

/**
 * The top-level meta an operation's document declares: the type's
 * {@see \haddowg\JsonApi\Serializer\DeclaresDocumentMetaInterface::documentMeta()} members
 * for a CRUD operation, the related type's for a related endpoint, and the declared
 * response's for a custom action. Read from the resource, or a composed type's serializer,
 * once per server, type and operation.
 *
 * The {@see CrudOperationHandler} fills the members an `extractUsing()` hook supplies, the
 * {@see \haddowg\JsonApiBundle\EventListener\ViewListener} checks a response against it in
 * debug mode, and the {@see \haddowg\JsonApiBundle\Server\ServableResourceWarmer} reads every
 * declaration at warm-up so a misdeclaration fails the build.
 */
final class DocumentMetaResolver
{
    /** @var array<string, DocumentMeta> */
    private array $declared = [];

    public function __construct(private readonly TypeMetadataResolver $types) {}

    /**
     * The members of the document `$operation` answers with. Given the response's
     * `$status`, a status that does not carry that document, such as a create's `202`, has
     * none, and an action's `201` is its {@see ActionCreated} while any other status is its
     * {@see ActionResource} or {@see MetaResult}.
     */
    public function forOperation(Server $server, JsonApiOperationInterface $operation, ?int $status = null): DocumentMeta
    {
        $target = $operation instanceof CustomActionOperation ? null : self::target($operation);
        [$type, $kind, $documentStatus] = match (true) {
            $operation instanceof FetchResourceOperation => [$target, $operation->target()->id === null ? OperationType::FetchCollection : OperationType::FetchOne, 200],
            $operation instanceof CreateResourceOperation => [$target, OperationType::Create, 201],
            $operation instanceof UpdateResourceOperation => [$target, OperationType::Update, 200],
            default => [null, null, 200],
        };
        if ($status !== null && $status !== $documentStatus && !$operation instanceof CustomActionOperation) {
            return new DocumentMeta();
        }

        return match (true) {
            $type !== null && $kind !== null => $this->forType($server, $type, $kind),
            $operation instanceof FetchRelatedOperation => $this->forRelation(
                $server,
                $this->types->relationNamed($server, $operation->target()->type, (string) $operation->target()->relationship),
            ),
            $operation instanceof CustomActionOperation => $this->forAction($operation, $status ?? 200),
            default => new DocumentMeta(),
        };
    }

    private static function target(JsonApiOperationInterface $operation): ?string
    {
        return match (true) {
            $operation instanceof FetchResourceOperation, $operation instanceof CreateResourceOperation, $operation instanceof UpdateResourceOperation => $operation->target()->type,
            default => null,
        };
    }

    /**
     * The members `$type` declares for `$operation`.
     */
    public function forType(Server $server, string $type, OperationType $operation): DocumentMeta
    {
        $key = \spl_object_id($server) . "\0" . $type . "\0" . $operation->value;

        if (!isset($this->declared[$key])) {
            $declarer = $this->types->resourceFor($server, $type)
                ?? ($server->hasSerializerFor($type) ? $server->serializerFor($type) : null);
            $this->declared[$key] = $declarer === null
                ? new DocumentMeta([], $operation === OperationType::FetchCollection)
                : DocumentMeta::forOperation($declarer, $operation, $type);
        }

        return $this->declared[$key];
    }

    /**
     * The members a related endpoint serves: the related type's `FetchCollection` members
     * for a to-many and its `FetchOne` members for a to-one. A polymorphic relation serves
     * no single type, so none.
     */
    public function forRelation(Server $server, ?RelationInterface $relation): DocumentMeta
    {
        if ($relation === null || !Polymorphism::isMonomorphic($relation)) {
            return new DocumentMeta();
        }

        return $this->forType($server, $relation->relatedTypes()[0], $relation->isToMany() ? OperationType::FetchCollection : OperationType::FetchOne);
    }

    private function forAction(CustomActionOperation $operation, int $status): DocumentMeta
    {
        $owner = \sprintf('the "%s" action of type "%s"', $operation->action(), $operation->target()->type);
        foreach ($operation->responds() as $response) {
            $matches = $status === 201
                ? $response instanceof ActionCreated
                : $response instanceof ActionResource || $response instanceof MetaResult;
            if ($matches) {
                return DocumentMeta::forResponse($response, $owner);
            }
        }

        return new DocumentMeta([], false, $owner);
    }
}
