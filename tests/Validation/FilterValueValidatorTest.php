<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Validation;

use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApiBundle\Validation\ConstraintTranslator;
use haddowg\JsonApiBundle\Validation\FilterValueValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * Unit coverage for the {@see FilterValueValidator}: it translates a filter's
 * declared value constraints through the same {@see ConstraintTranslator} the
 * attribute bridge uses and validates each client-supplied `filter[<key>]` value,
 * throwing {@see FilterValueInvalid} (`400`) on a violation. The functional twin
 * ({@see \haddowg\JsonApiBundle\Tests\Functional\FilterValueConstraintConformanceTestCase})
 * asserts the same behaviour end-to-end on both providers; this test pins the
 * value-shape and only-client-supplied semantics in isolation.
 */
final class FilterValueValidatorTest extends TestCase
{
    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aValidScalarValuePasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator()->validate(
            ['year' => '2024'],
            [Where::make('year')->integer()],
        );
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aMistypedScalarValueThrowsAFilterValueInvalid(): void
    {
        try {
            $this->validator()->validate(
                ['year' => 'banana'],
                [Where::make('year')->integer()],
            );
            self::fail('Expected a FilterValueInvalid.');
        } catch (FilterValueInvalid $exception) {
            self::assertSame(400, $exception->getStatusCode());
            self::assertSame('year', $exception->filterKey);
            self::assertNotEmpty($exception->messages);

            $error = $exception->getErrors()[0];
            self::assertSame('400', $error->status);
            self::assertSame('FILTER_VALUE_INVALID', $error->code);
            self::assertSame('filter[year]', $error->source?->parameter);
        }
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aFilterWithoutConstraintsIsNeverValidated(): void
    {
        $this->expectNotToPerformAssertions();

        // No constraints declared: any value passes, exactly as today.
        $this->validator()->validate(
            ['title' => 'anything goes'],
            [Where::make('title')],
        );
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function anAbsentClientValueIsNotValidated(): void
    {
        $this->expectNotToPerformAssertions();

        // The constrained filter is declared but the request carries no value for
        // it — a default would be folded in later, never validated here.
        $this->validator()->validate(
            [],
            [Where::make('year')->integer()],
        );
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function eachMemberOfADelimitedSetIsValidated(): void
    {
        try {
            $this->validator()->validate(
                ['id' => '1,banana,3'],
                [WhereIdIn::make()->integer()],
            );
            self::fail('Expected a FilterValueInvalid.');
        } catch (FilterValueInvalid $exception) {
            self::assertSame('id', $exception->filterKey);
            self::assertNotEmpty($exception->messages);
        }
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function eachMemberOfAnArraySetIsValidated(): void
    {
        try {
            $this->validator()->validate(
                ['id' => ['1', '2', 'banana']],
                [WhereIdIn::make()->integer()],
            );
            self::fail('Expected a FilterValueInvalid.');
        } catch (FilterValueInvalid $exception) {
            self::assertSame('id', $exception->filterKey);
        }
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aValidSetPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator()->validate(
            ['id' => '1,2,3'],
            [WhereIdIn::make()->integer()],
        );
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aUuidConstraintRejectsANonUuid(): void
    {
        try {
            $this->validator()->validate(
                ['ref' => 'not-a-uuid'],
                [Where::make('ref')->uuid()],
            );
            self::fail('Expected a FilterValueInvalid.');
        } catch (FilterValueInvalid $exception) {
            self::assertSame('ref', $exception->filterKey);
        }
    }

    private function validator(): FilterValueValidator
    {
        return new FilterValueValidator(Validation::createValidator(), new ConstraintTranslator());
    }
}
