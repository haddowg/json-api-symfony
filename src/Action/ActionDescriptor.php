<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Action;

/**
 * The resolved, scalar declaration of a single custom action (bundle ADR 0076) —
 * the {@see \haddowg\JsonApiBundle\Attribute\AsJsonApiAction} metadata flattened
 * into the values the {@see ActionInvoker} and the route loader consume, with the
 * decoupled-document defaults already applied: `inputType`/`outputType` resolve to
 * the mount `type` when the attribute left them `null`.
 *
 * `handlerServiceId` is the service id of the
 * {@see ActionHandlerInterface} that handles the action, resolved through the
 * {@see ActionRegistry}'s handler service-locator. `server` is the (already
 * resolved) server name the action is exposed on (the implicit `default` when the
 * attribute left it `null`).
 */
final readonly class ActionDescriptor
{
    /**
     * @param list<string> $methods the author-declared HTTP method allow-list
     * @param list<string> $tags    the OpenAPI tag refs this action is grouped under (resolved: explicit, else the mount type's resource tags, else empty)
     */
    public function __construct(
        public string $type,
        public string $path,
        public array $methods,
        public ActionScope $scope,
        public ActionInput $input,
        public string $inputType,
        public string $outputType,
        public ?string $security,
        public string $handlerServiceId,
        public string $server,
        public array $tags = [],
    ) {}
}
