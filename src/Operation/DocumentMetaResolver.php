<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Operation;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Operation\AddToRelationshipOperation;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\RemoveFromRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Resource\DocumentMeta;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\RelationshipMetaContext;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\DataProvider\Polymorphism;
use haddowg\JsonApiBundle\OpenApi\Metadata\DeclaredOperationResponse;
use haddowg\JsonApiBundle\Server\RouteDescriptorRegistry;
use haddowg\JsonApiBundle\Server\ServerName;
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

    public function __construct(
        private readonly TypeMetadataResolver $types,
        // The declared success responses, which say whether a delete answers a meta-only 200.
        private readonly ?RouteDescriptorRegistry $descriptors = null,
    ) {}

    /**
     * The members of the document `$operation` answers with. Given the response's
     * `$status`, a status that does not carry that document, such as a create's `202`, has
     * none, and an action's `201` is its {@see \haddowg\JsonApi\OpenApi\Metadata\ActionCreated} while any other status is its
     * {@see \haddowg\JsonApi\OpenApi\Metadata\ActionResource} or {@see \haddowg\JsonApi\OpenApi\Metadata\MetaResult}.
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
        // A write accepted for later processing answers its job's document.
        if ($status === 202 && $type !== null && ($kind === OperationType::Create || $kind === OperationType::Update)) {
            return $this->forAccepted($server, $type, $kind);
        }
        if ($status !== null && $status !== $documentStatus && !$operation instanceof CustomActionOperation && !$operation instanceof DeleteResourceOperation) {
            return new DocumentMeta();
        }

        return match (true) {
            $type !== null && $kind !== null => $this->forType($server, $type, $kind),
            $operation instanceof FetchRelatedOperation => $this->forRelation(
                $server,
                $this->types->relationNamed($server, $operation->target()->type, (string) $operation->target()->relationship),
            ),
            $operation instanceof CustomActionOperation => DocumentMeta::forActionStatus(
                $operation->responds(),
                $status ?? 200,
                \sprintf('the "%s" action of type "%s"', $operation->action(), $operation->target()->type),
            ),
            $operation instanceof DeleteResourceOperation => $status !== null && $status !== 200
                ? new DocumentMeta()
                : $this->forDelete($server, self::serverName($operation), $operation->target()->type),
            $operation instanceof UpdateRelationshipOperation, $operation instanceof AddToRelationshipOperation, $operation instanceof RemoveFromRelationshipOperation => $this->forRelationshipWrite(
                $server,
                $operation->target()->type,
                new RelationshipMetaContext(
                    (string) $operation->target()->relationship,
                    match (true) {
                        $operation instanceof AddToRelationshipOperation => Mode::Add,
                        $operation instanceof RemoveFromRelationshipOperation => Mode::Remove,
                        default => Mode::Replace,
                    },
                    $operation->context()->httpRequest()?->getAttribute(RequestMetaResolver::ATOMIC_ATTRIBUTE) === true,
                ),
            ),
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

    /**
     * The members of the meta-only `200` a delete of `$type` answers with: none unless the
     * type declares that status, else its `Delete` members.
     */
    public function forDelete(Server $server, string $serverName, string $type): DocumentMeta
    {
        $key = \spl_object_id($server) . "\0" . $serverName . "\0" . $type . "\0delete";
        if (!isset($this->declared[$key])) {
            $responses = [];
            foreach ($this->descriptors?->forType($serverName, $type)['responses'][OperationType::Delete->value] ?? [] as $entry) {
                $responses[] = new DeclaredOperationResponse($entry['status'], $entry['jobType'] ?? null);
            }
            $this->declared[$key] = DocumentMeta::forDelete($this->forType($server, $type, OperationType::Delete), $responses, $type);
        }

        return $this->declared[$key];
    }

    /**
     * The members of the meta-only `200` the relationship write `$context` names answers
     * with, which the parent type `$type` declares. Empty when it declares none, so the
     * write answers its linkage.
     */
    public function forRelationshipWrite(Server $server, string $type, RelationshipMetaContext $context): DocumentMeta
    {
        $key = \spl_object_id($server) . "\0" . $type . "\0relationship\0" . $context->describe();
        if (!isset($this->declared[$key])) {
            $declarer = $this->types->resourceFor($server, $type)
                ?? ($server->hasSerializerFor($type) ? $server->serializerFor($type) : null);
            $this->declared[$key] = DocumentMeta::forRelationship($declarer ?? new \stdClass(), $context, $type);
        }

        return $this->declared[$key];
    }

    /**
     * The members of the job document a create's or an update's `202` answers with, which
     * `$type` declares through `acceptedDocumentMeta()`.
     */
    public function forAccepted(Server $server, string $type, OperationType $operation): DocumentMeta
    {
        $key = \spl_object_id($server) . "\0" . $type . "\0accepted\0" . $operation->value;
        if (!isset($this->declared[$key])) {
            $declarer = $this->types->resourceFor($server, $type)
                ?? ($server->hasSerializerFor($type) ? $server->serializerFor($type) : null);
            $this->declared[$key] = DocumentMeta::forAccepted($declarer ?? new \stdClass(), $operation, [], $type);
        }

        return $this->declared[$key];
    }

    private static function serverName(JsonApiOperationInterface $operation): string
    {
        $name = $operation->context()->httpRequest()?->getAttribute('_jsonapi_server');

        return \is_string($name) && $name !== '' ? $name : ServerName::of($operation->context()->server);
    }

}
