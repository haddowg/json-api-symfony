<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Validation;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApi\Resource\Constraint\ProvidesJsonSchema;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
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
 * A hand-written composite constraint, one whose `contribute()` adds a
 * `oneOf`/`anyOf`/`allOf`, documents its shape without a translator registered,
 * while a scalar constraint the bridge can't translate still fails loud.
 */
final class AuthoredCompositeConstraintTest extends TestCase
{
    #[Test]
    public function aWriteToAFieldCarryingAnAuthoredCompositeNeedsNoTranslator(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator()->validate(
            $this->resource(new AuthoredGeometry()),
            $this->request(['name' => 'Home', 'location' => ['type' => 'Point', 'coordinates' => [0, 0]]]),
            creating: true,
        );
    }

    #[Test]
    public function theFieldsOtherConstraintsStillRun(): void
    {
        $resource = $this->resource(new AuthoredGeometry());

        try {
            $this->validator()->validate($resource, $this->request(['location' => ['type' => 'Point']]), creating: true);
            self::fail('Expected a ValidationFailed for the missing required name.');
        } catch (ValidationFailed $failed) {
            self::assertSame('/data/attributes/name', $failed->getErrors()[0]->source?->pointer);
        }
    }

    #[Test]
    public function anUntranslatableScalarConstraintStillFailsLoud(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(AuthoredHexFormat::class);

        $this->validator()->validate(
            $this->resource(new AuthoredHexFormat()),
            $this->request(['name' => 'Home', 'location' => ['a' => 1]]),
            creating: true,
        );
    }

    private function resource(ProvidesJsonSchema $constraint): AbstractResource
    {
        return new class ($constraint) extends AbstractResource {
            public static string $type = 'places';

            public function __construct(private readonly ProvidesJsonSchema $constraint) {}

            public function fields(): array
            {
                return [
                    Id::make(),
                    Str::make('name')->required(),
                    ArrayHash::make('location')->nullable()->constrain($this->constraint),
                ];
            }
        };
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function request(array $attributes): JsonApiRequest
    {
        $body = ['data' => ['type' => 'places', 'attributes' => $attributes]];

        return new JsonApiRequest(
            (new ServerRequest('POST', '/places', ['Content-Type' => 'application/vnd.api+json']))
                ->withParsedBody($body),
        );
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

final readonly class AuthoredGeometry implements ProvidesJsonSchema
{
    public function __construct(public Context $context = new Context()) {}

    public function context(): Context
    {
        return $this->context;
    }

    public function contribute(Schema $schema): Schema
    {
        return $schema->withOneOf([
            Schema::ofType('object')
                ->withProperties(['type' => Schema::ofType('string')->withConst('Point')])
                ->withRequired(['type']),
            Schema::ofType('object')
                ->withProperties(['type' => Schema::ofType('string')->withConst('LineString')])
                ->withRequired(['type']),
        ])->withDiscriminator('type');
    }
}

final readonly class AuthoredHexFormat implements ProvidesJsonSchema
{
    public function __construct(public Context $context = new Context()) {}

    public function context(): Context
    {
        return $this->context;
    }

    public function contribute(Schema $schema): Schema
    {
        return $schema->withPattern('^[0-9a-f]+$');
    }
}
