<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Validation;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Validation\ConstraintTranslator;
use haddowg\JsonApiBundle\Validation\JsonPointerBuilder;
use haddowg\JsonApiBundle\Validation\RelationshipTypeUnacceptable;
use haddowg\JsonApiBundle\Validation\ResourceValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Validation;

/**
 * Unit coverage for the relationship-linkage resource-type guard in
 * {@see ResourceValidator::validateRelationshipLinkage()}: a linkage whose `type` is
 * not among the relation's declared related types
 * ({@see RelationInterface::relatedTypes()}) is a `409`
 * {@see RelationshipTypeUnacceptable} — the linkage twin of core's create-path
 * `ResourceTypeUnacceptable`. The functional twin
 * ({@see \haddowg\JsonApiBundle\Tests\Functional\MultiTypeEntityConformanceTestCase})
 * proves the same end-to-end on both providers and on both the relationship-endpoint
 * and whole-resource-write paths; this test pins the cases a functional kernel cannot
 * easily reach: a **polymorphic** relation accepting each of its declared types, and a
 * relation declaring **no** related type accepting anything.
 */
final class RelationshipLinkageTypeGuardTest extends TestCase
{
    #[Test]
    #[Group('spec:updating-relationships')]
    public function aWrongTypeToOneLinkageIsAFourOhNineConflict(): void
    {
        $relation = BelongsTo::make('author', 'authors');

        try {
            $this->validator()->validateRelationshipLinkage(
                $relation,
                new ToOneRelationship(new ResourceIdentifier('comments', '1')),
            );
            self::fail('Expected a RelationshipTypeUnacceptable.');
        } catch (RelationshipTypeUnacceptable $exception) {
            self::assertSame(409, $exception->getStatusCode());

            $error = $exception->getErrors()[0];
            self::assertSame('409', $error->status);
            self::assertSame('RESOURCE_TYPE_UNACCEPTABLE', $error->code);
            self::assertSame('/data/type', $error->source?->pointer);
        }
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aCorrectTypeToOneLinkageIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator()->validateRelationshipLinkage(
            BelongsTo::make('author', 'authors'),
            new ToOneRelationship(new ResourceIdentifier('authors', '1')),
        );
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aWrongTypeToManyMemberIsAFourOhNineConflictPointingAtThatMember(): void
    {
        $relation = HasMany::make('comments', 'comments');

        try {
            $this->validator()->validateRelationshipLinkage(
                $relation,
                new ToManyRelationship([
                    new ResourceIdentifier('comments', '1'),
                    new ResourceIdentifier('authors', '2'), // the offending member
                ]),
                Mode::Add,
            );
            self::fail('Expected a RelationshipTypeUnacceptable.');
        } catch (RelationshipTypeUnacceptable $exception) {
            $errors = $exception->getErrors();
            self::assertCount(1, $errors);
            // Only the second member is wrong, so the pointer carries its index.
            self::assertSame('/data/1/type', $errors[0]->source?->pointer);
        }
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aPolymorphicRelationAcceptsEachOfItsDeclaredTypes(): void
    {
        $this->expectNotToPerformAssertions();

        $relation = MorphTo::make('pinned', ['notes', 'images']);

        // Each declared type is accepted — a polymorphic relation must NOT false-reject
        // a member matching ANY of its inverse types.
        $this->validator()->validateRelationshipLinkage(
            $relation,
            new ToOneRelationship(new ResourceIdentifier('notes', '1')),
        );
        $this->validator()->validateRelationshipLinkage(
            $relation,
            new ToOneRelationship(new ResourceIdentifier('images', '2')),
        );
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aPolymorphicRelationStillRejectsATypeOutsideItsDeclaredSet(): void
    {
        $relation = MorphTo::make('pinned', ['notes', 'images']);

        try {
            $this->validator()->validateRelationshipLinkage(
                $relation,
                new ToOneRelationship(new ResourceIdentifier('boards', '1')),
            );
            self::fail('Expected a RelationshipTypeUnacceptable.');
        } catch (RelationshipTypeUnacceptable $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }
    }

    private function validator(): ResourceValidator
    {
        return new ResourceValidator(
            Validation::createValidator(),
            new ConstraintTranslator(),
            new JsonPointerBuilder(),
            // An empty locator: the type guard never reaches the id-format path (a wrong
            // type pre-empts it), and a correct type resolves no format constraints here,
            // so any id passes — exactly what these type-only cases need.
            new IdEncoderResolver(new ResourceLocator(new Container(), [])),
        );
    }
}
