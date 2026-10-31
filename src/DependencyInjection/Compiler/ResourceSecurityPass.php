<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DependencyInjection\Compiler;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Security\ResourceSecurityRegistry;
use haddowg\JsonApiBundle\Security\UnenforceableSecurityException;
use haddowg\JsonApiBundle\Server\ServableResourceWarmer;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects the declarative authorization expressions a resource declares via
 * `#[AsJsonApiResource(security: …, securityCreate: …, …)]` (bundle ADR 0043) — the
 * extension records each of the six declarations as a tag attribute on the
 * {@see JsonApiBundle::RESOURCE_TAG} — and assembles a
 * `type → {default, create, update, delete, read, list}` scalar map per server,
 * injected into the {@see ResourceSecurityRegistry} the
 * {@see \haddowg\JsonApiBundle\Security\ResourceSecuritySubscriber} queries. The
 * default server's map is the `$expressions` argument and every other server's goes
 * under `$servers`. A resource assigned to several servers is recorded under each, and
 * a server only ever holds what its own resource declared (bundle ADR 0034).
 *
 * The maps flow as plain scalars (the container cannot dump value objects as a
 * service argument); the registry rebuilds the {@see \haddowg\JsonApiBundle\Security\ResourceSecurity}
 * objects from them.
 *
 * It also fails the build (bundle ADR 0043) when a resource or a custom action
 * declares an enforced expression (a string) the application cannot evaluate:
 * symfony/expression-language or symfony/security-core missing, or no
 * `security.authorization_checker` service (no SecurityBundle firewall). The
 * documentation-only `true`/`false` declarations need none of these. Relation
 * security lives in each resource's `fields()`, which a compiler pass cannot read, so
 * the pass hands what is missing to the {@see ServableResourceWarmer}, which checks
 * relations at warm-up.
 */
final class ResourceSecurityPass implements CompilerPassInterface
{
    private const array ATTRIBUTE_NAMES = [
        'default' => 'security',
        'create' => 'securityCreate',
        'update' => 'securityUpdate',
        'delete' => 'securityDelete',
        'read' => 'securityRead',
        'list' => 'securityList',
    ];

    /**
     * @param bool|null $expressionLanguageInstalled whether symfony/expression-language is installed (`null` detects it)
     * @param bool|null $securityCoreInstalled       whether symfony/security-core is installed (`null` detects it)
     */
    public function __construct(
        private readonly ?bool $expressionLanguageInstalled = null,
        private readonly ?bool $securityCoreInstalled = null,
    ) {}

    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, array<string, array{default?: string|bool, create?: string|bool, update?: string|bool, delete?: string|bool, read?: string|bool, list?: string|bool}>> $byServer */
        $byServer = [];

        /** @var list<string> $enforced */
        $enforced = [];

        foreach ($container->findTaggedServiceIds(JsonApiBundle::RESOURCE_TAG) as $id => $tags) {
            $definition = $container->findDefinition($id);
            $class = $definition->getClass() ?? (\is_string($id) && \class_exists($id) ? $id : null);
            if ($class === null) {
                continue;
            }

            // A resource bears two RESOURCE_TAG tags (AbstractResource
            // autoconfiguration + the attribute callback), so the type and each
            // expression are resolved across all tags — the empty autoconfig tag must
            // not erase the attribute's values.
            $type = $this->typeFromTags($tags, $class);
            if ($type === '') {
                continue;
            }

            $servers = $this->serversFromTags($tags);

            foreach (['default', 'create', 'update', 'delete', 'read', 'list'] as $key) {
                $value = $this->securityFromTags($tags, $key);
                if ($value !== null) {
                    foreach ($servers as $server) {
                        $byServer[$server][$type][$key] = $value;
                    }
                }
                if (\is_string($value)) {
                    $enforced[] = \sprintf('type "%s" (%s)', $type, self::ATTRIBUTE_NAMES[$key]);
                }
            }
        }

        foreach ($container->findTaggedServiceIds(JsonApiBundle::ACTION_TAG) as $tags) {
            foreach ($tags as $tag) {
                $security = $tag['security'] ?? null;
                if (\is_string($security) && $security !== '') {
                    $enforced[] = \sprintf(
                        'action "%s" on type "%s"',
                        \is_string($tag['path'] ?? null) ? $tag['path'] : '?',
                        \is_string($tag['type'] ?? null) ? $tag['type'] : '?',
                    );
                    break;
                }
            }
        }

        $missing = UnenforceableSecurityException::missingPieces(
            $this->expressionLanguageInstalled ?? \class_exists(\Symfony\Component\ExpressionLanguage\Expression::class),
            $this->securityCoreInstalled ?? \interface_exists(\Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface::class),
            $container->has('security.authorization_checker'),
        );

        if ($enforced !== [] && $missing !== []) {
            throw UnenforceableSecurityException::for(\array_values(\array_unique($enforced)), $missing);
        }

        if ($container->hasDefinition(ResourceSecurityRegistry::class)) {
            $expressions = $byServer[ServerProvider::DEFAULT_SERVER] ?? [];
            unset($byServer[ServerProvider::DEFAULT_SERVER]);

            $container->getDefinition(ResourceSecurityRegistry::class)
                ->setArgument('$expressions', $expressions)
                ->setArgument('$servers', $byServer);
        }

        if ($container->hasDefinition(ServableResourceWarmer::class)) {
            $container->getDefinition(ServableResourceWarmer::class)->setArgument('$unenforceableSecurity', $missing);
        }
    }

    /**
     * The JSON:API type for a resource across its tags: the first tag's `type`
     * override, else the `AbstractResource`'s static `$type`.
     *
     * @param array<array<string, mixed>> $tags
     */
    private function typeFromTags(array $tags, string $class): string
    {
        foreach ($tags as $tag) {
            $type = $tag['type'] ?? null;
            if (\is_string($type) && $type !== '') {
                return $type;
            }
        }

        return \is_a($class, AbstractResource::class, true) ? $class::$type : '';
    }

    /**
     * The servers a resource is exposed on, read across all its tags the way the
     * ResourceLocatorPass reads them (which also validates the names): the first tag
     * carrying a `server` value, comma-split, else the implicit `default`.
     *
     * @param array<array<string, mixed>> $tags
     *
     * @return list<string>
     */
    private function serversFromTags(array $tags): array
    {
        foreach ($tags as $tag) {
            if (!\array_key_exists('server', $tag)) {
                continue;
            }

            $names = \is_string($tag['server'])
                ? \array_values(\array_unique(\array_filter(\array_map('trim', \explode(',', $tag['server'])), static fn(string $name): bool => $name !== '')))
                : [];

            return $names !== [] ? $names : [ServerProvider::DEFAULT_SERVER];
        }

        return [ServerProvider::DEFAULT_SERVER];
    }

    /**
     * The security declaration recorded under the `security_<key>` tag attribute across
     * a resource's tags — a non-empty expression string, a bool (`true`/`false`), or
     * `null` when no tag carries one. A bool is a documentation-only declaration
     * (true = secured, false = public); a string is an enforced expression.
     *
     * @param array<array<string, mixed>> $tags
     */
    private function securityFromTags(array $tags, string $key): string|bool|null
    {
        $attribute = 'security_' . $key;
        foreach ($tags as $tag) {
            $value = $tag[$attribute] ?? null;
            if (\is_bool($value)) {
                return $value;
            }
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
