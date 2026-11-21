<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Security;

use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\Action\ActionRegistry;
use haddowg\JsonApiBundle\Security\OperationSecurityFacts;
use haddowg\JsonApiBundle\Security\ReadableRows;
use haddowg\JsonApiBundle\Security\ResourceSecurityRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Which relations a filter may not reach through: decided at runtime by
 * {@see ReadableRows::refusesThrough()} and documented by
 * {@see OperationSecurityFacts::canRefuseThrough()}.
 */
#[CoversClass(ReadableRows::class)]
#[CoversClass(OperationSecurityFacts::class)]
final class ThroughReadSecurityTest extends TestCase
{
    #[Test]
    public function anExpressionReadsTheSubjectThroughObjectOrSubject(): void
    {
        self::assertTrue(ReadableRows::readsSubject("is_granted('EDIT', object)"));
        self::assertTrue(ReadableRows::readsSubject('subject.owner == user.getUserIdentifier()'));
        self::assertFalse(ReadableRows::readsSubject("is_granted('ROLE_ADMIN')"));
        self::assertFalse(ReadableRows::readsSubject("user.object == 'x'"));
        self::assertFalse(ReadableRows::readsSubject("'object' in ['object']"));
    }

    #[Test]
    public function theRelationsOwnPermissionReplacesTheRelatedTypesGates(): void
    {
        $rows = $this->rows(['ROLE_ADMIN' => false]);

        self::assertTrue($rows->refusesThrough($this->relation(read: "is_granted('ROLE_ADMIN')")));
        self::assertTrue($rows->refusesThrough($this->relation(read: "object.name == 'alpha'")), 'a permission reading the parent answers for no single parent');
        self::assertFalse($rows->refusesThrough($this->relation(read: true)));
        self::assertFalse($rows->refusesThrough($this->relation(read: false)));
        self::assertFalse($this->rows(['ROLE_ADMIN' => true])->refusesThrough($this->relation(read: "is_granted('ROLE_ADMIN')")));
    }

    #[Test]
    public function theRelatedTypesListGateAndARowFreeReadGateRefuse(): void
    {
        $denied = $this->rows(['ROLE_ADMIN' => false, 'ROLE_USER' => true]);
        self::assertTrue($denied->refusesThrough($this->relation(type: 'vaults')));
        self::assertTrue($denied->refusesThrough($this->relation(type: 'safes')));
        self::assertFalse($denied->refusesThrough($this->relation(type: 'notes')), 'a read gate that reads the row is left to the scope');
        self::assertFalse($denied->refusesThrough($this->relation(type: 'ledgers')));
        self::assertFalse($this->rows(['ROLE_ADMIN' => true, 'ROLE_USER' => true])->refusesThrough($this->relation(type: 'vaults')));
    }

    #[Test]
    public function theDocumentOffersARefusalWhereSomeCallerCanBeRefused(): void
    {
        $facts = new OperationSecurityFacts(new ActionRegistry(new ServiceLocator([]), []), $this->registry());

        self::assertTrue($facts->canRefuseThrough('default', $this->relation(type: 'vaults')));
        self::assertTrue($facts->canRefuseThrough('default', $this->relation(type: 'safes')));
        self::assertTrue($facts->canRefuseThrough('default', $this->relation(type: 'notes')), 'its list gate refuses a caller without the role');
        self::assertFalse($facts->canRefuseThrough('default', $this->relation(type: 'memos')), 'a read gate that reads the row is left to the scope');
        self::assertFalse($facts->canRefuseThrough('default', $this->relation(type: 'ledgers')));
        self::assertTrue($facts->canRefuseThrough('default', $this->relation(type: 'ledgers', read: "is_granted('ROLE_ADMIN')")));
        self::assertFalse($facts->canRefuseThrough('default', $this->relation(type: 'vaults', read: true)));
    }

    /**
     * @param array<string, bool> $roles
     */
    private function rows(array $roles): ReadableRows
    {
        $checker = new class ($roles) implements AuthorizationCheckerInterface {
            /**
             * @param array<string, bool> $roles
             */
            public function __construct(private readonly array $roles) {}

            public function isGranted(mixed $attribute, mixed $subject = null, mixed $accessDecision = null): bool
            {
                $expression = $attribute instanceof Expression ? (string) $attribute : '';
                foreach ($this->roles as $role => $granted) {
                    if (\str_contains($expression, $role)) {
                        return $granted;
                    }
                }

                return $subject !== null;
            }
        };

        return new ReadableRows($checker, $this->registry());
    }

    private function registry(): ResourceSecurityRegistry
    {
        return new ResourceSecurityRegistry([
            'vaults' => ['default' => "is_granted('ROLE_USER')", 'list' => "is_granted('ROLE_ADMIN')"],
            'safes' => ['default' => "is_granted('ROLE_USER')", 'read' => "is_granted('ROLE_ADMIN')"],
            'notes' => ['default' => "is_granted('EDIT', object)", 'list' => "is_granted('ROLE_USER')"],
            'memos' => ['read' => "is_granted('EDIT', object)"],
        ]);
    }

    private function relation(string $type = 'ledgers', string|bool|null $read = null): RelationInterface
    {
        $relation = BelongsTo::make('target', $type);
        if ($read !== null) {
            $relation->security(read: $read);
        }

        return $relation->build();
    }
}
