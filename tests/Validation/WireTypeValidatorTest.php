<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Validation;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Validation\ConstraintTranslator;
use haddowg\JsonApiBundle\Validation\JsonPointerBuilder;
use haddowg\JsonApiBundle\Validation\ResourceValidator;
use haddowg\JsonApiBundle\Validation\ValidationFailed;
use haddowg\JsonApiBundle\Validation\WireTypeValidator;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Validation;

/**
 * The type check that stands in for the validator when `symfony/validator` is absent:
 * attributes and pivot values of the wrong JSON type are refused at their pointers. And
 * the validator itself checks linkage and pivot values when `attributes` is not an object.
 */
#[CoversClass(WireTypeValidator::class)]
#[CoversClass(ResourceValidator::class)]
final class WireTypeValidatorTest extends TestCase
{
    #[Test]
    public function aWritesPivotValueOfTheWrongTypeIsRefused(): void
    {
        self::assertSame(
            [['/data/relationships/tags/data/0/meta/pivot/rank', 'This value should be of type integer.']],
            $this->errorsOf(fn() => (new WireTypeValidator())->validate(new WireTypeTrackResource(), $this->request([
                'type' => 'tracks',
                'attributes' => ['title' => 'Intro'],
                'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '1', 'meta' => ['pivot' => ['rank' => 'first']]]]]],
            ]), true)),
        );
    }

    #[Test]
    public function anEndpointsPivotValueOfTheWrongTypeIsRefused(): void
    {
        $relation = (new WireTypeTrackResource())->relationNamed('tags');
        self::assertInstanceOf(RelationInterface::class, $relation);

        self::assertSame(
            [['/data/1/meta/pivot/rank', 'This value should be of type integer.']],
            $this->errorsOf(fn() => (new WireTypeValidator())->validateRelationshipLinkage($relation, new ToManyRelationship([
                new ResourceIdentifier('tags', '1', meta: ['pivot' => ['rank' => 1]]),
                new ResourceIdentifier('tags', '2', meta: ['pivot' => ['rank' => 'second']]),
            ]), Mode::Add)),
        );
    }

    #[Test]
    public function aRemovalCarriesNoPivotValues(): void
    {
        $relation = (new WireTypeTrackResource())->relationNamed('tags');
        self::assertInstanceOf(RelationInterface::class, $relation);

        (new WireTypeValidator())->validateRelationshipLinkage($relation, new ToManyRelationship([
            new ResourceIdentifier('tags', '2', meta: ['pivot' => ['rank' => 'second']]),
        ]), Mode::Remove);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function theValidatorChecksPivotValuesWhenAttributesIsNotAnObject(): void
    {
        $validator = new ResourceValidator(
            Validation::createValidator(),
            new ConstraintTranslator(),
            new JsonPointerBuilder(),
            new IdEncoderResolver(new ResourceLocator(new Container(), [])),
        );

        self::assertSame(
            [['/data/relationships/tags/data/0/meta/pivot/rank', 'This value should be of type integer.']],
            $this->errorsOf(fn() => $validator->validate(new WireTypeTrackResource(), $this->request([
                'type' => 'tracks',
                'attributes' => 'none',
                'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '1', 'meta' => ['pivot' => ['rank' => 'first']]]]]],
            ]), true)),
        );
    }

    /**
     * @param \Closure(): void $validate
     *
     * @return list<array{0: string|null, 1: string|null}>
     */
    private function errorsOf(\Closure $validate): array
    {
        try {
            $validate();
        } catch (ValidationFailed $e) {
            return \array_map(static fn($error): array => [$error->source?->pointer, $error->detail], $e->getErrors());
        }

        self::fail('Expected a ValidationFailed.');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function request(array $data): JsonApiRequest
    {
        return new JsonApiRequest((new ServerRequest('POST', '/tracks'))->withParsedBody(['data' => $data]));
    }
}

final class WireTypeTrackResource extends AbstractResource
{
    public static string $type = 'tracks';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            BelongsToMany::make('tags', 'tags')->fields(Integer::make('rank')),
        ];
    }
}
