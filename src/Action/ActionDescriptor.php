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
 *
 * `outputType` is the **runtime** response serializer the {@see ActionContext} renders
 * a {@see \haddowg\JsonApi\Response\DataResponse} through — the type named by the
 * `responds` set's `ActionResource` entry when present, else the mount `type`.
 *
 * `responds` is the declared success-response set the OpenAPI projection advertises
 * (core ADR 0127): each entry a `kind` discriminator (`resource`/`meta`/`nocontent`/
 * `accepted`/`seeother`) plus the `type` an `ActionResource` names or the `jobType` an
 * `Accepted` names. The {@see \haddowg\JsonApiBundle\OpenApi\Metadata\ActionMetadata}
 * rehydrates each into a core response object. It stays a plain scalar list so the
 * descriptor survives the compiled container.
 */
final readonly class ActionDescriptor
{
    /**
     * @param list<string>                                              $methods  the author-declared HTTP method allow-list
     * @param list<array{kind: string, type?: string, jobType?: string}> $responds the declared success-response set (OpenAPI projection)
     * @param list<string>                                              $tags     the OpenAPI tag refs this action is grouped under (resolved: explicit, else the mount type's resource tags, else empty)
     * @param bool                                                      $asLink   expose the action as a security-aware `links` member on the mount type's resources (resource scope only)
     */
    public function __construct(
        public string $type,
        public string $path,
        public array $methods,
        public ActionScope $scope,
        public ActionInput $input,
        public string $inputType,
        public string $outputType,
        public array $responds,
        public ?string $security,
        public string $handlerServiceId,
        public string $server,
        public array $tags = [],
        public bool $asLink = false,
    ) {}
}
