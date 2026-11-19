<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Operation;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\ActionRequestMetaContext;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApi\Resource\MetaShape;
use haddowg\JsonApi\Resource\RequestMeta;
use haddowg\JsonApi\Resource\RequestMetaContext;
use haddowg\JsonApi\Resource\ResourceMeta;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;
use haddowg\JsonApiBundle\Validation\ResourceValidator;
use haddowg\JsonApiBundle\Validation\ValidationFailed;

/**
 * The declared `meta` of a write: the top-level `meta` a type's
 * {@see \haddowg\JsonApi\Hydrator\DeclaresRequestDocumentMetaInterface::requestDocumentMeta()}
 * declares per write, the resource object `meta` its
 * {@see \haddowg\JsonApi\Serializer\DeclaresResourceMetaInterface::resourceMeta()} declares
 * per direction, and an action handler's request `meta`. Read from the resource, or a composed type's hydrator
 * or serializer, once per server, type and context.
 *
 * The {@see CrudOperationHandler} validates each write against it and fills what the
 * hooks take, and the {@see \haddowg\JsonApiBundle\Server\ServableResourceWarmer} reads every
 * declaration at warm-up so a misdeclaration fails the build.
 */
final class RequestMetaResolver
{
    /**
     * The request attribute marking a sub-request as one operation of an Atomic Operations
     * batch, whose declarations are read with the atomic flag set.
     */
    public const string ATOMIC_ATTRIBUTE = '_jsonapi_atomic';

    /** @var array<string, MetaShape> */
    private array $declared = [];

    public function __construct(private readonly TypeMetadataResolver $types) {}

    /**
     * The members `$type` declares for the top-level `meta` of the write `$context` names.
     */
    public function forWrite(Server $server, string $type, RequestMetaContext $context): MetaShape
    {
        $key = \spl_object_id($server) . "\0" . $type . "\0request\0" . $context->describe();

        return $this->declared[$key] ??= RequestMeta::forOperation($this->hydrator($server, $type) ?? new \stdClass(), $context, $type);
    }

    /**
     * The members `$type` declares for its resource objects' `meta` in `$context`'s
     * direction: read from the serializer, or for a write from the hydrator when it declares
     * them, the resource serving both when the type has one.
     */
    public function forResource(Server $server, string $type, MetaContext $context): MetaShape
    {
        $direction = $context->isRead() ? 'read' : ($context->creating() ? 'create' : 'update') . ($context->isAtomic() ? ':atomic' : '');
        $key = \spl_object_id($server) . "\0" . $type . "\0resource\0" . $direction;
        if (!isset($this->declared[$key])) {
            $hydrator = $this->hydrator($server, $type);
            $declarer = $context->isWrite() && $hydrator instanceof \haddowg\JsonApi\Serializer\DeclaresResourceMetaInterface ? $hydrator : $this->serializer($server, $type);
            $this->declared[$key] = ResourceMeta::forType($declarer ?? new \stdClass(), $context, $type);
        }

        return $this->declared[$key];
    }

    /**
     * The members an action's handler declares for the top-level `meta` of a call with
     * `$method`.
     */
    public function forAction(object $handler, string $method, string $owner): MetaShape
    {
        $key = \spl_object_id($handler) . "\0action\0" . \strtoupper($method);

        return $this->declared[$key] ??= RequestMeta::forAction($handler, new ActionRequestMetaContext($method), $owner);
    }

    /**
     * The violations of one declared `meta` object a write sent: the Validator bridge's when
     * it is wired, else the undeclared members and the values of the wrong JSON type core
     * finds. `$pointer` locates the object, such as `/meta` or `/data/meta`.
     *
     * @return list<Error>
     */
    public static function errors(?ResourceValidator $validator, MetaShape $shape, mixed $values, string $pointer, bool $creating, JsonApiRequestInterface $request): array
    {
        if ($validator !== null) {
            return $validator->metaErrors($shape, $values, $pointer, $creating, $request);
        }
        if ($shape->isEmpty() || !\is_array($values)) {
            return [];
        }

        $errors = [];
        foreach ($shape->undeclared($values, $pointer) as $undeclared) {
            $errors[] = ValidationFailed::describe()->toError(detail: 'The member is not declared.', source: ErrorSource::fromPointer($undeclared));
        }
        $mismatched = [];
        foreach ($shape->mismatches($values, $pointer, $creating, $request) as $mismatch) {
            $mismatched[$mismatch->pointer] = true;
            $errors[] = ValidationFailed::describe()->toError(detail: \sprintf('This value should be of type %s.', $mismatch->expected), source: ErrorSource::fromPointer($mismatch->pointer));
        }
        // A value in a shape its member does not take, such as a date-time with no offset.
        foreach ($shape->writableFields($creating, $request) as $field) {
            $value = $values[$field->name()] ?? null;
            if ($value === null || isset($mismatched[$pointer . '/' . $field->name()])) {
                continue;
            }
            try {
                $field->castWireValue($value);
            } catch (\haddowg\JsonApi\Exception\AttributeValueInvalid $invalid) {
                $errors[] = ValidationFailed::describe()->toError(detail: $invalid->reason, source: ErrorSource::fromPointer($pointer . '/' . $invalid->attribute));
            }
        }

        return $errors;
    }

    /**
     * The top-level `meta` of a request, or `null` when its body carries none. A request
     * with an empty body has none, so a bodiless call is never refused for its body.
     */
    public static function documentMetaOf(JsonApiRequestInterface $request): mixed
    {
        if ((string) $request->getBody() === '') {
            return null;
        }
        $parsed = $request->getParsedBody();

        return \is_array($parsed) && \array_key_exists('meta', $parsed) ? $parsed['meta'] : null;
    }

    private function hydrator(Server $server, string $type): ?object
    {
        return $this->types->resourceFor($server, $type)
            ?? ($server->hasHydratorFor($type) ? $server->hydratorFor($type) : null);
    }

    private function serializer(Server $server, string $type): ?object
    {
        return $this->types->resourceFor($server, $type)
            ?? ($server->hasSerializerFor($type) ? $server->serializerFor($type) : null);
    }
}
