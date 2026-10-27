<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Action;

use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\OperationReference;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Link\ActionLink;
use haddowg\JsonApi\Schema\Link\OperationLink;
use haddowg\JsonApi\Serializer\ResourceLinkContributorInterface;
use haddowg\JsonApi\Serializer\UriTypeAwareInterface;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Contributes a custom action's URL as an out-of-band `links` member on every
 * rendered resource of its mount type — core's
 * {@see ResourceLinkContributorInterface} seam — when the action declared
 * `asLink: true` on its {@see \haddowg\JsonApiBundle\Attribute\AsJsonApiAction}, or
 * when the type's resource declares an {@see ActionLink} for the action's path in its
 * `links()` (bundle ADR 0159).
 *
 * It is **per server**: each declared server's memoized
 * {@see \haddowg\JsonApi\Server\Server} is threaded its own contributor (via
 * {@see \haddowg\JsonApi\Server\Server::withResourceLinkContributor()}), holding only
 * that server's resource-scope actions — so an action exposed on server A never leaks a
 * link onto server B's resources.
 *
 * It is **security-aware**: when an action declared a `security` expression, the link
 * is rendered only when the requester would pass that gate, evaluated through the
 * SAME {@see AuthorizationCheckerInterface} the per-action `BeforeActionEvent` gate
 * uses (the {@see \haddowg\JsonApiBundle\Security\ResourceSecuritySubscriber}) — with
 * the rendered object as the subject — so a client never sees a link to an action it
 * cannot invoke. An action with no `security` always renders its link. With no
 * authorization checker a `security`-gated action's link is suppressed, so the
 * document never advertises an action behind a gate it cannot evaluate. The build
 * fails in that case anyway (bundle ADR 0130), so this is a fallback.
 *
 * When security passes, a declared {@see ActionLink}'s `when()` condition decides,
 * with the rendered object and the request. A declared action link wins over
 * `asLink`, so its condition applies even when the action also sets `asLink`.
 *
 * The href is the action route's path relative to the server, `/{uriType}/{id}/-actions/{path}`,
 * and core prefixes it with the same base as the resource's `self` link: the configured
 * `base_uri`, or the request origin plus the recorded mount when that is empty (bundle
 * ADR 0121). Generating it through the router instead would base it on the request
 * host and ignore a configured `base_uri`.
 *
 * Each link names the action's operation (its path under the type's URI segment and its
 * first declared method), so where the server has a `describedby` resolver the link
 * object carries a `describedby` pointing at the action in the OpenAPI document.
 *
 * Resolved lazily through the resolver-aware resource (core reads it off the rendered
 * resource's serializer resolver), so a bare serializer that never opted into
 * resolver-awareness receives no contribution — exactly as for relationships.
 */
final readonly class ActionLinkContributor implements ResourceLinkContributorInterface
{
    /**
     * @param array<string, array<string, list<array{path: string, security: ?string, methods?: list<string>, asLink?: bool}>>> $actionsByServerType the resource-scope actions that may render as a link, keyed by server name then by mount JSON:API type, in registration order
     */
    public function __construct(
        private ServerProvider $servers,
        private ?AuthorizationCheckerInterface $authorizationChecker = null,
        private array $actionsByServerType = [],
    ) {}

    public function linksFor(mixed $object, string $type, JsonApiRequestInterface $request): array
    {
        $server = $this->serverName($request);

        $descriptors = $this->actionsByServerType[$server][$type] ?? [];
        if ($descriptors === []) {
            return [];
        }

        $jsonApiServer = $this->servers->get($server);
        $declared = [];
        if ($jsonApiServer->hasResourceFor($type)) {
            foreach ($jsonApiServer->resourceFor($type)->allActionLinks() as $actionLink) {
                $declared[$actionLink->path] = $actionLink;
            }
        }

        $descriptors = \array_filter(
            $descriptors,
            static fn(array $descriptor): bool => isset($declared[$descriptor['path']]) || ($descriptor['asLink'] ?? true),
        );
        if ($descriptors === []) {
            return [];
        }

        $serializer = $jsonApiServer->serializerFor($type);
        $id = $serializer->getId($object);
        if ($id === '') {
            // A not-yet-persisted resource (e.g. rendered mid-create) has no id to
            // build the action URL from — skip, mirroring core's convention self link.
            return [];
        }

        $uriType = $serializer instanceof UriTypeAwareInterface && $serializer->uriType() !== ''
            ? $serializer->uriType()
            : $type;

        $links = [];
        foreach ($descriptors as $descriptor) {
            if (!$this->isVisible($descriptor['security'], $object)) {
                continue;
            }
            if (isset($declared[$descriptor['path']]) && !$declared[$descriptor['path']]->appliesTo($object, $request)) {
                continue;
            }

            $links[$descriptor['path']] = new OperationLink(
                '/' . $uriType . '/' . $id . '/-actions/' . $descriptor['path'],
                OperationReference::action($uriType, $descriptor['path'], ActionScope::Resource, $descriptor['methods'][0] ?? 'POST'),
            );
        }

        return $links;
    }

    /**
     * Whether the action's `security` expression admits the current requester — the
     * SAME evaluation the per-action `BeforeActionEvent` gate uses (an `is_granted`
     * Expression against the rendered object as the subject). A `null` expression
     * always admits. With no authorization checker wired the link is suppressed for a
     * gated action, matching the invocation gate, which refuses the request.
     */
    private function isVisible(?string $security, mixed $object): bool
    {
        if ($security === null) {
            return true;
        }

        if ($this->authorizationChecker === null) {
            return false;
        }

        return $this->authorizationChecker->isGranted(new Expression($security), $object);
    }

    /**
     * The server the request resolved on, read from the `_jsonapi_server` request
     * attribute the route loader stamps (the same mechanism the
     * {@see \haddowg\JsonApiBundle\Action\ActionInvoker} resolves it with),
     * defaulting to the implicit `default` server.
     */
    private function serverName(JsonApiRequestInterface $request): string
    {
        $name = $request->getAttribute('_jsonapi_server');

        return \is_string($name) && $name !== '' ? $name : ServerProvider::DEFAULT_SERVER;
    }
}
