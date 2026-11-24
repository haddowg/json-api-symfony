<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Security;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Resource\Field\AbstractRelationBuilder;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApiBundle\Action\ActionRegistry;
use haddowg\JsonApiBundle\OpenApi\Metadata\RelationMetadata;
use haddowg\JsonApiBundle\Security\OperationSecurityFacts;
use haddowg\JsonApiBundle\Security\ResourceSecurityRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The document offers a refusal on a related or relationship read only where its endpoint
 * can refuse one: a to-many by a related type's collection read expression, which a `true`
 * declaration is not, since nothing in the bundle enforces it there.
 */
#[CoversClass(OperationSecurityFacts::class)]
final class RelatedReadSecurityFactsTest extends TestCase
{
    #[Test]
    public function onlyAnEnforcedCollectionGateOfARelatedTypeCanRefuseAToManyRead(): void
    {
        self::assertTrue($this->canDeny(BelongsToMany::make('spokes', 'spokes')));
        self::assertFalse($this->canDeny(BelongsToMany::make('badges', 'badges')), 'a true list declaration is not enforced on a related read');
        self::assertFalse($this->canDeny(BelongsToMany::make('rims', 'rims')));
        self::assertFalse($this->canDeny(BelongsTo::make('spoke', 'spokes')), 'a to-one needs no collection gate');
        self::assertFalse($this->canDeny(BelongsToMany::make('shared', 'spokes')->security(read: false)), 'the relation\'s own read replaces it');
    }

    private function canDeny(AbstractRelationBuilder $builder): bool
    {
        $relation = $builder->build();
        $type = $this->createStub(TypeMetadataInterface::class);
        $type->method('type')->willReturn('hubs');
        $type->method('relations')->willReturn([new RelationMetadata($relation, null, [])]);

        $facts = new OperationSecurityFacts(new ActionRegistry(new ServiceLocator([]), []), new ResourceSecurityRegistry([
            'spokes' => ['list' => "is_granted('ROLE_ADMIN')"],
            'badges' => ['list' => true],
        ]));

        $related = $facts->canDenyAccess('default', [], $type, OperationKey::relation(OperationKind::FetchRelated, $relation->name()));
        $relationship = $facts->canDenyAccess('default', [], $type, OperationKey::relation(OperationKind::FetchRelationship, $relation->name()));
        self::assertSame($related, $relationship);

        return $related;
    }
}
