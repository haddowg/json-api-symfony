<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DependencyInjection\Compiler;

use haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceSecurityPass;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Security\ResourceSecurityRegistry;
use haddowg\JsonApiBundle\Security\UnenforceableSecurityException;
use haddowg\JsonApiBundle\Server\ServableResourceWarmer;
use haddowg\JsonApiBundle\Tests\Functional\App\SecurityEnforcement\PlainGadgetResource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The build-time check of {@see ResourceSecurityPass} (bundle ADR 0130), with the
 * package checks injected so a missing symfony/expression-language or
 * symfony/security-core can be simulated.
 */
final class ResourceSecurityPassTest extends TestCase
{
    #[Test]
    public function anExpressionWithoutExpressionLanguageFailsTheBuild(): void
    {
        $container = $this->container(['security_update' => "is_granted('ROLE_ADMIN')"], checker: true);

        $this->expectException(UnenforceableSecurityException::class);
        $this->expectExceptionMessage('type "guard-gadgets" (securityUpdate)');
        $this->expectExceptionMessage('composer require symfony/expression-language');

        (new ResourceSecurityPass(expressionLanguageInstalled: false, securityCoreInstalled: true))->process($container);
    }

    #[Test]
    public function anExpressionWithoutSecurityCoreFailsTheBuild(): void
    {
        $container = $this->container(['security_default' => "is_granted('ROLE_USER')"], checker: false);

        try {
            (new ResourceSecurityPass(expressionLanguageInstalled: true, securityCoreInstalled: false))->process($container);
            self::fail('The build should fail.');
        } catch (UnenforceableSecurityException $exception) {
            self::assertStringContainsString('type "guard-gadgets" (security)', $exception->getMessage());
            self::assertStringContainsString('composer require symfony/security-core', $exception->getMessage());
            self::assertStringContainsString('security.authorization_checker', $exception->getMessage());
            self::assertStringNotContainsString('expression-language', $exception->getMessage());
        }
    }

    #[Test]
    public function anActionExpressionWithoutACheckerFailsTheBuild(): void
    {
        $container = $this->container([], checker: false);
        $container->setDefinition('app.action', (new Definition(\stdClass::class))->addTag(JsonApiBundle::ACTION_TAG, [
            'type' => 'guard-gadgets',
            'path' => 'inspect',
            'security' => "is_granted('ROLE_ADMIN')",
        ]));

        $this->expectException(UnenforceableSecurityException::class);
        $this->expectExceptionMessage('action "inspect" on type "guard-gadgets"');

        (new ResourceSecurityPass(expressionLanguageInstalled: true, securityCoreInstalled: true))->process($container);
    }

    #[Test]
    public function documentationOnlyDeclarationsNeedNoPackages(): void
    {
        $container = $this->container(['security_default' => true, 'security_list' => false], checker: false);

        (new ResourceSecurityPass(expressionLanguageInstalled: false, securityCoreInstalled: false))->process($container);

        self::assertSame(
            ['guard-gadgets' => ['default' => true, 'list' => false]],
            $container->getDefinition(ResourceSecurityRegistry::class)->getArgument('$expressions'),
        );
        // Relation expressions are checked at warm-up, so the warmer learns what is missing.
        self::assertSame(
            [UnenforceableSecurityException::EXPRESSION_LANGUAGE, UnenforceableSecurityException::SECURITY_CORE, UnenforceableSecurityException::AUTHORIZATION_CHECKER],
            $container->getDefinition(ServableResourceWarmer::class)->getArgument('$unenforceableSecurity'),
        );
    }

    #[Test]
    public function noDeclarationsNeedNoPackages(): void
    {
        $container = $this->container([], checker: false);

        (new ResourceSecurityPass(expressionLanguageInstalled: false, securityCoreInstalled: false))->process($container);

        self::assertSame([], $container->getDefinition(ResourceSecurityRegistry::class)->getArgument('$expressions'));
    }

    #[Test]
    public function anEnforceableExpressionBuilds(): void
    {
        $container = $this->container(['security_read' => "is_granted('ROLE_USER')"], checker: true);

        (new ResourceSecurityPass(expressionLanguageInstalled: true, securityCoreInstalled: true))->process($container);

        self::assertSame(
            ['guard-gadgets' => ['read' => "is_granted('ROLE_USER')"]],
            $container->getDefinition(ResourceSecurityRegistry::class)->getArgument('$expressions'),
        );
        self::assertSame([], $container->getDefinition(ServableResourceWarmer::class)->getArgument('$unenforceableSecurity'));
    }

    /**
     * @param array<string, string|bool> $security the `security_*` tag attributes of the one resource
     */
    private function container(array $security, bool $checker): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ResourceSecurityRegistry::class, (new Definition(ResourceSecurityRegistry::class))->setArgument('$expressions', []));
        $container->setDefinition(ServableResourceWarmer::class, (new Definition(ServableResourceWarmer::class))->setArgument('$unenforceableSecurity', []));
        $container->setDefinition(PlainGadgetResource::class, (new Definition(PlainGadgetResource::class))
            ->addTag(JsonApiBundle::RESOURCE_TAG)
            ->addTag(JsonApiBundle::RESOURCE_TAG, $security));

        if ($checker) {
            $container->setDefinition('security.authorization_checker', new Definition(\stdClass::class));
        }

        return $container;
    }
}
