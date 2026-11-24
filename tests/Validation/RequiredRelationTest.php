<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Validation;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Validation\ConstraintTranslator;
use haddowg\JsonApiBundle\Validation\JsonPointerBuilder;
use haddowg\JsonApiBundle\Validation\ResourceValidator;
use haddowg\JsonApiBundle\Validation\ValidationFailed;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Validation;

/**
 * The relation shapes the functional suite does not reach: a required `HasOne` and
 * `MorphTo`, a nullable required to-one, a required to-many that also bounds its
 * members, and a relation read-only on create.
 */
final class RequiredRelationTest extends TestCase
{
    #[Test]
    public function aCreateLeavingOutARequiredHasOneOrMorphToIsRefused(): void
    {
        self::assertSame([
            ['This field is missing.', '/data/relationships/profile'],
            ['This field is missing.', '/data/relationships/subject'],
        ], $this->violations(['team' => ['data' => null], 'members' => $this->members()]));
    }

    #[Test]
    public function aNullHasOneOrMorphToIsRefused(): void
    {
        self::assertSame([
            ['This value should not be null.', '/data/relationships/profile/data'],
            ['This value should not be null.', '/data/relationships/subject/data'],
        ], $this->violations([
            'profile' => ['data' => null],
            'subject' => ['data' => null],
            'team' => ['data' => ['type' => 'teams', 'id' => '1']],
            'members' => $this->members(),
        ]));
    }

    #[Test]
    public function aNullableRequiredToOneMustBeSentButMayBeNull(): void
    {
        self::assertSame([['This field is missing.', '/data/relationships/team']], $this->violations($this->required() + ['members' => $this->members()]));
        self::assertSame([], $this->violations($this->required() + ['team' => ['data' => null], 'members' => $this->members()]));
    }

    #[Test]
    public function aRequiredToManyBoundingItsMembersReportsAnEmptySetOnce(): void
    {
        self::assertSame(
            [['This collection should contain 1 element or more.', '/data/relationships/members/data']],
            $this->violations($this->required() + ['team' => ['data' => null], 'members' => ['data' => []]]),
        );
    }

    #[Test]
    public function aRelationReadOnlyOnCreateIsNotRequiredOnCreate(): void
    {
        self::assertSame([], $this->violations($this->required() + ['team' => ['data' => null], 'members' => $this->members()]));
    }

    #[Test]
    public function aRelationshipEndpointMayNotEmptyARequiredRelation(): void
    {
        $resource = $this->resource();
        $profile = $resource->relationNamed('profile');
        self::assertNotNull($profile);
        $members = $resource->relationNamed('members');
        self::assertNotNull($members);

        try {
            $this->validator()->validateRelationshipLinkage($profile, new ToOneRelationship(null));
            self::fail('Expected a ValidationFailed.');
        } catch (ValidationFailed $exception) {
            self::assertSame('/data', $exception->getErrors()[0]->source?->pointer);
        }

        // An empty set breaks the bound, which reports it on its own.
        try {
            $this->validator()->validateRelationshipLinkage($members, new ToManyRelationship([]));
            self::fail('Expected a ValidationFailed.');
        } catch (ValidationFailed $exception) {
            self::assertCount(1, $exception->getErrors());
            self::assertSame('This collection should contain 1 element or more.', $exception->getErrors()[0]->detail);
        }
    }

    /**
     * @return array{data: list<array{type: string, id: string}>}
     */
    private function members(): array
    {
        return ['data' => [['type' => 'members', 'id' => '1']]];
    }

    /**
     * @return array<string, mixed>
     */
    private function required(): array
    {
        return [
            'profile' => ['data' => ['type' => 'profiles', 'id' => '1']],
            'subject' => ['data' => ['type' => 'notes', 'id' => '1']],
        ];
    }

    /**
     * @param array<string, mixed> $relationships
     *
     * @return list<array{0: ?string, 1: ?string}>
     */
    private function violations(array $relationships): array
    {
        $body = ['data' => ['type' => 'players', 'relationships' => $relationships]];
        $request = new JsonApiRequest(
            (new ServerRequest('POST', '/players', ['Content-Type' => 'application/vnd.api+json']))->withParsedBody($body),
        );

        try {
            $this->validator()->validate($this->resource(), $request, creating: true);
        } catch (ValidationFailed $exception) {
            return \array_map(static fn($error): array => [$error->detail, $error->source?->pointer], $exception->getErrors());
        }

        return [];
    }

    private function resource(): AbstractResource
    {
        return new class extends AbstractResource {
            public static string $type = 'players';

            public function fields(): array
            {
                return [
                    Id::make(),
                    HasOne::make('profile', 'profiles')->required(),
                    MorphTo::make('subject', ['notes', 'images'])->requiredOnCreate(),
                    BelongsTo::make('team', 'teams')->required()->nullable(),
                    HasMany::make('members', 'members')->required()->minItems(1),
                    BelongsTo::make('coach', 'coaches')->readOnlyOnCreate()->required(),
                ];
            }
        };
    }

    private function validator(): ResourceValidator
    {
        return new ResourceValidator(
            Validation::createValidator(),
            new ConstraintTranslator(),
            new JsonPointerBuilder(),
            new IdEncoderResolver(new ResourceLocator(new Container(), [])),
        );
    }
}
