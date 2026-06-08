<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Validation;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Custom;
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
use haddowg\JsonApi\Resource\Constraint\Timezone;
use haddowg\JsonApi\Resource\Constraint\UniqueItems;
use haddowg\JsonApi\Resource\Constraint\UrlFormat;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use haddowg\JsonApiBundle\Validation\ConstraintTranslator;
use haddowg\JsonApiBundle\Validation\StrictEmailConstraintTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
use Symfony\Component\Validator\Constraints\Unique;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Constraints\Uuid;

final class ConstraintTranslatorTest extends TestCase
{
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
    public function itTranslatesTheStrictEmailCustomConstraint(): void
    {
        self::assertEquals(
            [new Email(mode: Email::VALIDATION_MODE_STRICT)],
            $this->translator()->translate(new Custom(StrictEmailConstraintTranslator::ID, true)),
        );
    }

    #[Test]
    public function itRejectsACustomConstraintWithNoRegisteredTranslator(): void
    {
        $this->expectException(\LogicException::class);

        $this->translator()->translate(new Custom('unregistered.id'));
    }

    #[Test]
    public function itRejectsAConstraintItDoesNotYetTranslate(): void
    {
        // Date/timezone value constraints are a documented follow-up; the bridge
        // fails loud rather than silently skipping the rule.
        $this->expectException(\LogicException::class);

        $this->translator()->translate(new Timezone(['UTC']));
    }

    private function translator(): ConstraintTranslator
    {
        return new ConstraintTranslator([new StrictEmailConstraintTranslator()]);
    }
}
