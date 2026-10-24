<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DependencyInjection\Compiler;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Security\ResourceSecurityRegistry;
use haddowg\JsonApiBundle\Security\UnenforceableSecurityException;
use haddowg\JsonApiBundle\Server\ServableResourceWarmer;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects the declarative authorization expressions a resource declares via
 * `#[AsJsonApiResource(security: …, securityCreate: …, …)]` (bundle ADR 0043) — the
 * extension records each of the six declarations as a tag attribute on the
 * {@see JsonApiBundle::RESOURCE_TAG} — and assembles a type-keyed
 * `type → {default, create, update, delete, read, list}` scalar map, injected into the
 * {@see ResourceSecurityRegistry} the {@see \haddowg\JsonApiBundle\Security\ResourceSecuritySubscriber}
 * queries.
 *
 * The map flows as plain scalars (the container cannot dump value objects as a
 * service argument); the registry rebuilds the {@see \haddowg\JsonApiBundle\Security\ResourceSecurity}
 * objects from it. Security is type-keyed and server-independent, so the `server`
 * tag attribute is irrelevant here.
 *
 * It also fails the build (bundle ADR 0130) when a resource or a custom action
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
        /** @var array<string, array{default?: string|bool, create?: string|bool, update?: string|bool, delete?: string|bool, read?: string|bool, list?: string|bool}> $expressions */
        $expressions = [];

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

            foreach (['default', 'create', 'update', 'delete', 'read', 'list'] as $key) {
                $value = $this->securityFromTags($tags, $key);
                if ($value !== null) {
                    $expressions[$type][$key] = $value;
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
            $container->getDefinition(ResourceSecurityRegistry::class)->setArgument('$expressions', $expressions);
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
