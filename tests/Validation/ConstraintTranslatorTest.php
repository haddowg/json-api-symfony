<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Validation;

use haddowg\JsonApi\Resource\Constraint\After;
use haddowg\JsonApi\Resource\Constraint\AtLeastOneOf;
use haddowg\JsonApi\Resource\Constraint\Before;
use haddowg\JsonApi\Resource\Constraint\Between;
use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApi\Resource\Constraint\Each;
use haddowg\JsonApi\Resource\Constraint\EmailFormat;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMax;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMin;
use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Constraint\IpFormat;
use haddowg\JsonApi\Resource\Constraint\Max;
use haddowg\JsonApi\Resource\Constraint\MaxItems;
use haddowg\JsonApi\Resource\Constraint\MaxLength;
use haddowg\JsonApi\Resource\Constraint\Min;
use haddowg\JsonApi\Resource\Constraint\MinItems;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Constraint\MultipleOf;
use haddowg\JsonApi\Resource\Constraint\NotIn;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\Sequentially;
use haddowg\JsonApi\Resource\Constraint\UlidFormat;
use haddowg\JsonApi\Resource\Constraint\UniqueItems;
use haddowg\JsonApi\Resource\Constraint\UrlFormat;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use haddowg\JsonApi\Resource\Constraint\When;
use haddowg\JsonApiBundle\Validation\Constraint\UniqueEntity;
use haddowg\JsonApiBundle\Validation\ConstraintTranslator;
use haddowg\JsonApiBundle\Validation\ConstraintTranslatorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity as DoctrineUniqueEntity;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\DivisibleBy;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\Ip;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\LessThan;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Ulid as SymfonyUlid;
use Symfony\Component\Validator\Constraints\Unique;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Constraints\Uuid;
use Symfony\Component\Validator\Validation;

final class ConstraintTranslatorTest extends TestCase
{
    use ClockSensitiveTrait;

    /**
     * @param list<Constraint> $expected
     */
    #[Test]
    #[DataProvider('mechanicalConstraints')]
    public function itTranslatesAConstraintToItsSymfonyEquivalent(ConstraintInterface $constraint, array $expected): void
    {
        self::assertEquals($expected, $this->translator()->translate($constraint));
    }

    /**
     * @return iterable<string, array{ConstraintInterface, list<Constraint>}>
     */
    public static function mechanicalConstraints(): iterable
    {
        yield 'In → Choice' => [new In(['a', 'b']), [new Choice(choices: ['a', 'b'])]];
        yield 'NotIn → negated Choice' => [new NotIn(['a']), [new Choice(choices: ['a'], match: false)]];
        yield 'Min → GreaterThanOrEqual' => [new Min(5), [new GreaterThanOrEqual(value: 5)]];
        yield 'Max → LessThanOrEqual' => [new Max(5), [new LessThanOrEqual(value: 5)]];
        yield 'ExclusiveMin → GreaterThan' => [new ExclusiveMin(5), [new GreaterThan(value: 5)]];
        yield 'ExclusiveMax → LessThan' => [new ExclusiveMax(5), [new LessThan(value: 5)]];
        yield 'MultipleOf → DivisibleBy' => [new MultipleOf(2), [new DivisibleBy(value: 2)]];
        yield 'MinLength → Length(min)' => [new MinLength(3), [new Length(min: 3)]];
        yield 'MaxLength → Length(max)' => [new MaxLength(50), [new Length(max: 50)]];
        yield 'MinItems → Count(min)' => [new MinItems(1), [new Count(min: 1)]];
        yield 'MaxItems → Count(max)' => [new MaxItems(9), [new Count(max: 9)]];
        yield 'UniqueItems → Unique' => [new UniqueItems(), [new Unique()]];
        yield 'EmailFormat → Email' => [new EmailFormat(), [new Email()]];
        yield 'UuidFormat (any) → Uuid' => [new UuidFormat(), [new Uuid()]];
        yield 'UuidFormat (v4) → Uuid v4' => [new UuidFormat(4), [new Uuid(versions: [4])]];
        yield 'IpFormat (any) → Ip all' => [new IpFormat(), [new Ip(version: Ip::ALL)]];
        yield 'IpFormat (v4) → Ip v4' => [new IpFormat(4), [new Ip(version: Ip::V4)]];
        yield 'Pattern → delimited Regex' => [new Pattern('[a-z]+'), [new Regex(pattern: '~[a-z]+~')]];
        yield 'Each → All of translated inner' => [new Each([new MinLength(2)]), [new All(constraints: [new Length(min: 2)])]];
    }

    #[Test]
    public function itTranslatesUlidFormatToAUlidConstraintOrTheRegexFallback(): void
    {
        // symfony/validator ships a dedicated Ulid constraint (since 5.4); where it is
        // present it is used, else the Crockford-base32 pattern is enforced with a Regex
        // so the constraint still has teeth. This build ships Ulid (asserted), and the
        // fallback shape is covered by exercising the produced rule below.
        $translated = $this->translator()->translate(new UlidFormat());
        self::assertCount(1, $translated);

        if (\class_exists(SymfonyUlid::class)) {
            self::assertEquals([new SymfonyUlid()], $translated);
        } else {
            self::assertInstanceOf(Regex::class, $translated[0]);
        }

        // The produced rule is exercised, not just its shape: a real ULID passes, a
        // non-ULID fails — so a regression that dropped or mis-wired the arm is caught.
        self::assertSame(0, $this->violations(new UlidFormat(), '01ARZ3NDEKTSV4RRFFQ69G5FAV'));
        self::assertGreaterThan(0, $this->violations(new UlidFormat(), 'not-a-ulid'));
    }

    #[Test]
    public function itTranslatesUrlFormatToAUrlConstraintWithTheAllowedProtocols(): void
    {
        // Asserted on the produced Url's protocols rather than by equality, so the
        // version-dependent `requireTld` option does not make the comparison brittle.
        $default = $this->translator()->translate(new UrlFormat());
        self::assertCount(1, $default);
        self::assertInstanceOf(Url::class, $default[0]);
        self::assertSame(['http', 'https'], $default[0]->protocols);

        $scoped = $this->translator()->translate(new UrlFormat(['ftp']));
        self::assertInstanceOf(Url::class, $scoped[0]);
        self::assertSame(['ftp'], $scoped[0]->protocols);
    }

    #[Test]
    public function itTranslatesAStrictEmailFormatToStrictMode(): void
    {
        // strict is typed config on EmailFormat now, not a Custom('email.strict').
        // egulias/email-validator is a dev dependency, so strict mode is available.
        self::assertEquals(
            [new Email(mode: Email::VALIDATION_MODE_STRICT)],
            $this->translator()->translate(new EmailFormat(true)),
        );
    }

    #[Test]
    public function itTranslatesUniqueEntityToTheDoctrineConstraint(): void
    {
        self::assertEquals(
            [new DoctrineUniqueEntity(fields: ['email'], message: 'This value is already used.')],
            $this->translator()->translate(new UniqueEntity(['email'])),
        );
    }

    #[Test]
    public function itDelegatesAConstraintOutsideTheVocabularyToARegisteredTranslator(): void
    {
        $constraint = $this->customConstraint();

        $translator = new ConstraintTranslator([
            new class implements ConstraintTranslatorInterface {
                public function supports(ConstraintInterface $constraint): bool
                {
                    return true;
                }

                public function translate(ConstraintInterface $constraint): array
                {
                    return [new Length(min: 5)];
                }
            },
        ]);

        self::assertEquals([new Length(min: 5)], $translator->translate($constraint));
    }

    #[Test]
    public function itAppliesWhenConstraintsOnlyWhileTheConditionHolds(): void
    {
        // Require a min length of 3, but only for a non-empty string value.
        $when = new When(
            condition: static fn(mixed $value): bool => \is_string($value) && $value !== '',
            constraints: [new MinLength(3)],
        );

        self::assertSame(1, $this->violations($when, 'ab'));  // condition holds, too short → violation
        self::assertSame(0, $this->violations($when, 'abc')); // condition holds, long enough → ok
        self::assertSame(0, $this->violations($when, ''));    // condition fails → inner skipped
    }

    #[Test]
    public function itTranslatesAfterToAStrictLowerDateBound(): void
    {
        $after = new After(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertSame(0, $this->violations($after, '2026-06-01T00:00:00+00:00')); // after → ok
        self::assertSame(1, $this->violations($after, '2025-12-01T00:00:00+00:00')); // before → violation
        self::assertSame(1, $this->violations($after, '2026-01-01T00:00:00+00:00')); // equal → violation (strict)
        self::assertSame(0, $this->violations($after, null));                        // absent → not this rule's concern
        self::assertSame(0, $this->violations($after, 'not a date'));                // unparseable → skipped
    }

    #[Test]
    public function itTranslatesBeforeToAStrictUpperDateBound(): void
    {
        $before = new Before(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertSame(0, $this->violations($before, '2025-06-01T00:00:00+00:00')); // before → ok
        self::assertSame(1, $this->violations($before, '2026-06-01T00:00:00+00:00')); // after → violation
        self::assertSame(1, $this->violations($before, '2026-01-01T00:00:00+00:00')); // equal → violation (strict)
    }

    #[Test]
    public function itTranslatesBetweenToAnInclusiveDateRange(): void
    {
        $between = new Between(
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new \DateTimeImmutable('2026-12-31T00:00:00+00:00'),
        );

        self::assertSame(0, $this->violations($between, '2026-06-01T00:00:00+00:00')); // inside
        self::assertSame(0, $this->violations($between, '2026-01-01T00:00:00+00:00')); // lower bound inclusive
        self::assertSame(0, $this->violations($between, '2026-12-31T00:00:00+00:00')); // upper bound inclusive
        self::assertSame(1, $this->violations($between, '2025-12-31T00:00:00+00:00')); // below
        self::assertSame(1, $this->violations($between, '2027-01-01T00:00:00+00:00')); // above
    }

    #[Test]
    public function itTranslatesSequentiallyApplyingConstraintsInOrder(): void
    {
        $sequentially = new Sequentially([new MinLength(3), new Pattern('^[a-z]+$')]);

        self::assertSame(0, $this->violations($sequentially, 'abcd')); // passes both
        self::assertSame(1, $this->violations($sequentially, 'ab'));   // fails minLength (stops there)
        self::assertSame(1, $this->violations($sequentially, 'ABCD')); // passes length, fails pattern
    }

    #[Test]
    public function itTranslatesAtLeastOneOf(): void
    {
        $atLeastOneOf = new AtLeastOneOf([new MinLength(8), new In(['none'])]);

        self::assertSame(0, $this->violations($atLeastOneOf, 'longenough')); // satisfies the length alternative
        self::assertSame(0, $this->violations($atLeastOneOf, 'none'));        // satisfies the enum alternative
        self::assertGreaterThan(0, $this->violations($atLeastOneOf, 'short')); // satisfies neither
    }

    #[Test]
    public function itResolvesAClosureBoundAtValidationTimeAgainstTheClock(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-06-08T12:00:00+00:00'));
        $after = new After(static fn(): \DateTimeImmutable => Clock::get()->now());

        self::assertSame(0, $this->violations($after, '2026-06-09T00:00:00+00:00')); // after frozen now → ok
        self::assertSame(1, $this->violations($after, '2026-06-07T00:00:00+00:00')); // before frozen now → violation
    }

    #[Test]
    public function itRejectsAConstraintWithNoRegisteredTranslator(): void
    {
        // A constraint outside the built-in vocabulary with no extension translator
        // registered fails loud rather than being silently skipped.
        $this->expectException(\LogicException::class);

        $this->translator()->translate($this->customConstraint());
    }

    private function translator(): ConstraintTranslator
    {
        return new ConstraintTranslator();
    }

    /**
     * A bespoke constraint outside core's built-in vocabulary.
     */
    private function customConstraint(): ConstraintInterface
    {
        return new class implements ConstraintInterface {
            public function context(): Context
            {
                return new Context();
            }
        };
    }

    /**
     * Runs the translated Symfony constraints against a value through a real
     * validator and returns the violation count, so the produced rules are
     * exercised — not just their shape.
     */
    private function violations(ConstraintInterface $constraint, mixed $value): int
    {
        return \count(Validation::createValidator()->validate($value, $this->translator()->translate($constraint)));
    }
}
