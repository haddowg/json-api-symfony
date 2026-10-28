<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationshipLinkage;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Security\ReadableRows;
use haddowg\JsonApiBundle\Security\ResourceSecurityRegistry;
use haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipLinkage;
use haddowg\JsonApiBundle\Serializer\WindowedRelationshipLinkage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The per-row read gate on a `MorphTo` declared with `identifiedBy()` (bundle ADR 0163).
 * Its linkage renders from the stored pair, so the gate never reads the target off the
 * parent: it answers `null` when nothing was loaded and checks the loaded row otherwise.
 */
final class RequestScopedRelationshipLinkageTest extends TestCase
{
    #[Test]
    public function anIdentifiedRelationWithNothingLoadedKeepsThePairWithoutReadingTheTarget(): void
    {
        $holder = $this->holder();

        self::assertNull($holder->toOneLinkageForRelationship(new \stdClass(), $this->relation(), $this->request()));
    }

    #[Test]
    public function aLoadedTargetIsCheckedAgainstItsTypesReadGate(): void
    {
        $holder = $this->holder();
        $relation = $this->relation();
        $visible = new \stdClass();
        $visible->hidden = false;
        $hidden = new \stdClass();
        $hidden->hidden = true;
        $visibleParent = new \stdClass();
        $hiddenParent = new \stdClass();

        $holder->setIncluded(new WindowedRelationshipLinkage([], [
            \spl_object_id($visibleParent) => ['target' => ToOneRelationshipLinkage::to($visible)],
            \spl_object_id($hiddenParent) => ['target' => ToOneRelationshipLinkage::to($hidden)],
        ]));

        self::assertSame($visible, $holder->toOneLinkageForRelationship($visibleParent, $relation, $this->request())?->target);

        $refused = $holder->toOneLinkageForRelationship($hiddenParent, $relation, $this->request());
        self::assertNotNull($refused);
        self::assertNull($refused->target);
    }

    private function holder(): RequestScopedRelationshipLinkage
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(
            static fn(mixed $attribute, mixed $subject = null): bool => !(\is_object($subject) && ($subject->hidden ?? false) === true),
        );

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('getType')->willReturn('agencies');
        $server = $this->createStub(SerializerResolverInterface::class);
        $server->method('hasSerializerFor')->willReturn(true);
        $server->method('serializerFor')->willReturn($serializer);

        $holder = new RequestScopedRelationshipLinkage(new ReadableRows($checker, new ResourceSecurityRegistry([
            'agencies' => ['read' => 'is_granted("VIEW", object)'],
        ])));
        $holder->bind($server);

        return $holder;
    }

    private function relation(): MorphTo
    {
        return MorphTo::make('target', ['advertisers', 'agencies'])
            ->identifiedBy(static fn(mixed $campaign): array => ['agencies', 1])
            ->extractUsing(static fn(): never => throw new \LogicException('The target was read off the parent.'))
            ->build();
    }

    private function request(): JsonApiRequestInterface
    {
        return $this->createStub(JsonApiRequestInterface::class);
    }
}
