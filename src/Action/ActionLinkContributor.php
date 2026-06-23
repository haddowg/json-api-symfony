<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Action;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Serializer\ResourceLinkContributorInterface;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Contributes a custom action's URL as an out-of-band `links` member on every
 * rendered resource of its mount type — core's
 * {@see ResourceLinkContributorInterface} seam — when the action declared
 * `asLink: true` on its {@see \haddowg\JsonApiBundle\Attribute\AsJsonApiAction}.
 *
 * It is **per server**: each declared server's memoized
 * {@see \haddowg\JsonApi\Server\Server} is threaded its own contributor (via
 * {@see \haddowg\JsonApi\Server\Server::withResourceLinkContributor()}), holding only
 * that server's `asLink` actions — so an action exposed on server A never leaks a
 * link onto server B's resources, and the route name resolves to that server's
 * namespaced route.
 *
 * It is **security-aware**: when an action declared a `security` expression, the link
 * is rendered only when the requester would pass that gate, evaluated through the
 * SAME {@see AuthorizationCheckerInterface} the per-action `BeforeActionEvent` gate
 * uses (the {@see \haddowg\JsonApiBundle\Security\ResourceSecuritySubscriber}) — with
 * the rendered object as the subject — so a client never sees a link to an action it
 * cannot invoke. An action with no `security` always renders its link; with no
 * authorization checker wired (no firewall) a `security`-gated action's link is
 * suppressed (fail-closed: the gate would deny at invocation too).
 *
 * The URL is generated through the framework's {@see UrlGeneratorInterface} for the
 * action's route, with the rendered object's id (resolved through the same serializer
 * the render uses) as the `{id}` parameter, as an absolute URL — so it matches the
 * request-host-absolute convention the by-convention `self` link follows, and core's
 * base-prefixing leaves an already-absolute href untouched.
 *
 * Resolved lazily through the resolver-aware resource (core reads it off the rendered
 * resource's serializer resolver), so a bare serializer that never opted into
 * resolver-awareness receives no contribution — exactly as for relationships.
 */
final readonly class ActionLinkContributor implements ResourceLinkContributorInterface
{
    /**
     * @param array<string, array<string, list<array{path: string, routeName: string, security: ?string}>>> $linksByServerType the `asLink` action link descriptors, keyed by server name then by mount JSON:API type
     */
    public function __construct(
        private ServerProvider $servers,
        private UrlGeneratorInterface $urlGenerator,
        private ?AuthorizationCheckerInterface $authorizationChecker = null,
        private array $linksByServerType = [],
    ) {}

    public function linksFor(mixed $object, string $type, JsonApiRequestInterface $request): array
    {
        $server = $this->serverName($request);

        $descriptors = $this->linksByServerType[$server][$type] ?? [];
        if ($descriptors === []) {
            return [];
        }

        $serializer = $this->servers->get($server)->serializerFor($type);
        $id = $serializer->getId($object);
        if ($id === '') {
            // A not-yet-persisted resource (e.g. rendered mid-create) has no id to
            // build the action URL from — skip, mirroring core's convention self link.
            return [];
        }

        $links = [];
        foreach ($descriptors as $descriptor) {
            if (!$this->isVisible($descriptor['security'], $object)) {
                continue;
            }

            $url = $this->urlGenerator->generate(
                $descriptor['routeName'],
                ['id' => $id],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );

            $links[$descriptor['path']] = new Link($url);
        }

        return $links;
    }

    /**
     * Whether the action's `security` expression admits the current requester — the
     * SAME evaluation the per-action `BeforeActionEvent` gate uses (an `is_granted`
     * Expression against the rendered object as the subject). A `null` expression
     * always admits. With no authorization checker wired the link is suppressed for a
     * gated action (fail-closed: the gate would deny at invocation too).
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
