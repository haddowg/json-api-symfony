<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Catalog\OperationCatalog;
use haddowg\JsonApi\Catalog\OperationDefinition;
use haddowg\JsonApi\Catalog\SecurityEvaluatorInterface;
use haddowg\JsonApi\Catalog\SecurityLevel;
use haddowg\JsonApi\Catalog\SecurityVerdict;
use haddowg\JsonApi\Scope\ScopeChecker;
use haddowg\JsonApiBundle\Tests\Functional\App\Scopes\ScopesInMemoryTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\SecurityEnforcement\SecurityEnforcementKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The operation catalogue (core ADR 0180): the scopes each operation lists, the security
 * rule and level the bundle reads for it, and the verdicts Symfony Security gives.
 */
#[Group('scopes')]
final class OperationCatalogTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected function setUp(): void
    {
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
        $this->restoreHandlers();
    }

    #[Test]
    public function theCatalogueListsEachOperationWithTheScopesEnforcementChecks(): void
    {
        static::ensureKernelShutdown();
        self::$class = ScopesInMemoryTestKernel::class;
        static::bootKernel(['debug' => false]);
        $operations = $this->operations();

        self::assertSame(['articles:write', 'articles:admin'], $operations['articles delete']->requiredScopes);
        self::assertSame(['articles:read', 'comments:read'], $operations['articles fetchRelated.comments']->requiredScopes);
        self::assertSame('/articles/{id}/comments', $operations['articles fetchRelated.comments']->pathTemplate);
        self::assertSame(['articles:publish'], $operations['articles action.publish.post']->requiredScopes);
        self::assertSame(SecurityLevel::None, $operations['articles delete']->securityLevel);

        $atomic = \array_values($operations)[\count($operations) - 1];
        self::assertNull($atomic->type);
        self::assertSame(['batch'], $atomic->requiredScopes);

        $checker = static::getContainer()->get(ScopeChecker::class);
        self::assertInstanceOf(ScopeChecker::class, $checker);
        self::assertTrue($checker->satisfies($operations['articles delete'], ['articles:write', 'articles:admin']));
        self::assertFalse($checker->satisfies($operations['articles delete'], ['articles:write']));
        self::assertTrue($checker->satisfies($operations['articles delete'], null));
    }

    #[Test]
    public function eachRuleIsReadAtTheLevelItNeeds(): void
    {
        $this->bootSecured();
        $operations = $this->operations();

        self::assertSame("is_granted('ROLE_ADMIN')", $operations['guard-gadgets fetchCollection']->security);
        self::assertSame(SecurityLevel::Type, $operations['guard-gadgets fetchCollection']->securityLevel);
        self::assertSame(SecurityLevel::Entity, $operations['guard-gadgets fetchOne']->securityLevel);
        self::assertFalse($operations['guard-gadgets fetchRelated.partner']->security);
        self::assertSame(SecurityLevel::None, $operations['guard-gadgets fetchRelated.partner']->securityLevel);
        self::assertSame(SecurityLevel::Type, $operations['guard-gadgets action.inspect.post']->securityLevel);
    }

    #[Test]
    public function theEvaluatorAllowsDeniesOrDefersToTheResource(): void
    {
        $this->bootSecured();
        $operations = $this->operations();
        $evaluator = static::getContainer()->get(SecurityEvaluatorInterface::class);
        self::assertInstanceOf(SecurityEvaluatorInterface::class, $evaluator);
        $admin = new InMemoryUser('ada', null, ['ROLE_ADMIN']);
        $user = new InMemoryUser('bob', null, ['ROLE_USER']);

        self::assertSame(SecurityVerdict::Allowed, $evaluator->evaluate($operations['guard-gadgets fetchCollection'], $admin));
        self::assertSame(SecurityVerdict::Denied, $evaluator->evaluate($operations['guard-gadgets fetchCollection'], $user));
        self::assertSame(SecurityVerdict::Denied, $evaluator->evaluate($operations['guard-gadgets action.inspect.post']));
        self::assertSame(SecurityVerdict::Depends, $evaluator->evaluate($operations['guard-gadgets fetchOne'], $admin));
        self::assertSame(SecurityVerdict::Allowed, $evaluator->evaluate($operations['guard-gadgets fetchRelated.partner'], $user));
    }

    private function bootSecured(): void
    {
        static::ensureKernelShutdown();
        self::$class = SecurityEnforcementKernel::class;
        SecurityEnforcementKernel::$variant = 'catalog';
        SecurityEnforcementKernel::$firewall = true;
        static::bootKernel(['debug' => false]);
    }

    /**
     * @return array<string, OperationDefinition> keyed by type and operation name
     */
    private function operations(): array
    {
        $catalog = static::getContainer()->get(OperationCatalog::class);
        self::assertInstanceOf(OperationCatalog::class, $catalog);

        $operations = [];
        foreach ($catalog->forServer('default') as $operation) {
            $operations[($operation->type ?? '') . ' ' . $operation->key->name()] = $operation;
        }

        return $operations;
    }

    private function restoreHandlers(): void
    {
        while (true) {
            $current = \set_error_handler(static fn(): bool => false);
            \restore_error_handler();
            if ($current === $this->errorHandler) {
                break;
            }
            \restore_error_handler();
        }

        while (true) {
            $current = \set_exception_handler(null);
            \restore_exception_handler();
            if ($current === $this->exceptionHandler) {
                break;
            }
            \restore_exception_handler();
        }
    }
}
