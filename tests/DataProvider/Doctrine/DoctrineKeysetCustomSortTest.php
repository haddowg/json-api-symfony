<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Collection\Keyset\KeysetColumn;
use haddowg\JsonApi\Pagination\CursorBoundary;
use haddowg\JsonApi\Resource\Sort\KeysetSortInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineKeyset;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineKeysetSortArmInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineSortArmInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineSortHandler;
use haddowg\JsonApiBundle\DataProvider\Doctrine\KeysetExpression;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A custom sort level of a Doctrine cursor page, built from the expression its
 * {@see DoctrineKeysetSortArmInterface} supplies.
 */
final class DoctrineKeysetCustomSortTest extends TestCase
{
    private string $previousTimezone;

    protected function setUp(): void
    {
        $this->previousTimezone = \date_default_timezone_get();
        \date_default_timezone_set('America/New_York');
    }

    protected function tearDown(): void
    {
        \date_default_timezone_set($this->previousTimezone);
    }

    #[Test]
    public function theLevelOrdersByTheArmsExpressionWithNullsLargest(): void
    {
        $builder = $this->builder();
        $this->keyset(new KeysetExpression('v.amount'))->orderBy($builder, [
            new KeysetColumn('value.cap', false, $this->sort()),
            new KeysetColumn('id', false),
        ]);

        self::assertSame(
            ['CASE WHEN v.amount IS NULL THEN 1 ELSE 0 END ASC', 'v.amount ASC', 'CASE WHEN resource.id IS NULL THEN 1 ELSE 0 END ASC', 'resource.id ASC'],
            $this->orderBy($builder),
        );
    }

    #[Test]
    public function theArmIsAskedOncePerQuery(): void
    {
        $arm = new RecordingKeysetArm(new KeysetExpression('v.amount', Types::INTEGER));
        $keyset = new DoctrineKeyset(new ClassMetadata(\stdClass::class), 'resource', sorts: new DoctrineSortHandler([$arm]));
        $columns = [new KeysetColumn('value.cap', true, $this->sort()), new KeysetColumn('id', true)];

        $builder = $this->builder();
        $keyset->applyAfter($builder, $this->boundary(['value.cap' => 30, 'id' => 4], $columns), $columns);
        $keyset->orderBy($builder, $columns);

        self::assertSame(1, $arm->calls);
        self::assertSame('resource', $arm->alias);
    }

    #[Test]
    public function theKeysetWhereComparesTheExpressionToTheBoundaryWithTheDeclaredType(): void
    {
        $columns = [new KeysetColumn('value.cap', false, $this->sort()), new KeysetColumn('id', false)];
        $builder = $this->builder();

        $this->keyset(new KeysetExpression('v.amount', Types::INTEGER))
            ->applyAfter($builder, $this->boundary(['value.cap' => 30, 'id' => 4], $columns), $columns);

        self::assertSame(
            '((v.amount > :jsonapi_cursor_0 OR v.amount IS NULL)) OR (v.amount = :jsonapi_cursor_2 AND ((resource.id > :jsonapi_cursor_1 OR resource.id IS NULL)))',
            $this->where($builder),
        );
        foreach (['jsonapi_cursor_0', 'jsonapi_cursor_2'] as $name) {
            self::assertSame(30, $this->parameter($builder, $name)->getValue());
            self::assertSame(Types::INTEGER, $this->parameter($builder, $name)->getType());
        }
    }

    #[Test]
    public function aNullBoundaryUsesTheNullAwareCases(): void
    {
        $descending = [new KeysetColumn('value.cap', true, $this->sort()), new KeysetColumn('id', true)];
        $builder = $this->builder();
        $this->keyset(new KeysetExpression('v.amount'))
            ->applyAfter($builder, $this->boundary(['value.cap' => null, 'id' => 6], $descending), $descending);

        self::assertSame(
            'v.amount IS NOT NULL OR (v.amount IS NULL AND resource.id < :jsonapi_cursor_0)',
            $this->where($builder),
        );

        $ascending = [new KeysetColumn('value.cap', false, $this->sort()), new KeysetColumn('id', false)];
        $builder = $this->builder();
        $this->keyset(new KeysetExpression('v.amount'))
            ->applyAfter($builder, $this->boundary(['value.cap' => null, 'id' => 3], $ascending), $ascending);

        self::assertSame('v.amount IS NULL AND ((resource.id > :jsonapi_cursor_0 OR resource.id IS NULL))', $this->where($builder));
    }

    #[Test]
    public function aNaiveDateTimeTypeBindsTheBoundaryInTheDefaultTimezone(): void
    {
        $bound = $this->boundValue(Types::DATETIME_IMMUTABLE, '2026-10-01T16:00:00.000000+00:00');

        self::assertInstanceOf(\DateTimeImmutable::class, $bound);
        self::assertSame('2026-10-01T12:00:00-04:00', $bound->format(\DateTimeInterface::ATOM));

        $mutable = $this->boundValue(Types::DATETIME_MUTABLE, '2026-10-01T16:00:00.000000+00:00');
        self::assertInstanceOf(\DateTime::class, $mutable);
        self::assertSame('2026-10-01T12:00:00-04:00', $mutable->format(\DateTimeInterface::ATOM));
    }

    #[Test]
    public function aZonedDateTimeTypeKeepsTheBoundarysOffset(): void
    {
        $bound = $this->boundValue(Types::DATETIMETZ_IMMUTABLE, '2026-10-01T16:00:00.000000+00:00');

        self::assertInstanceOf(\DateTimeImmutable::class, $bound);
        self::assertSame('2026-10-01T16:00:00+00:00', $bound->format(\DateTimeInterface::ATOM));
    }

    #[Test]
    public function withNoTypeTheBoundaryBindsInItsTokenForm(): void
    {
        self::assertSame('2026-10-01T16:00:00.000000+00:00', $this->boundValue(null, '2026-10-01T16:00:00.000000+00:00'));
    }

    #[Test]
    public function anArmWithoutTheKeysetContractIsAnUnsupportedSort(): void
    {
        $arm = new class implements DoctrineSortArmInterface {
            public function supports(SortInterface $sort): bool
            {
                return true;
            }

            public function apply(SortInterface $sort, QueryBuilder $query, bool $descending, string $alias): void {}
        };

        foreach ([[$arm], []] as $arms) {
            try {
                (new DoctrineSortHandler($arms))->keysetExpression($this->sort(), $this->builder(), 'resource');
                self::fail('Expected UnsupportedSort.');
            } catch (UnsupportedSort $e) {
                self::assertStringContainsString('DoctrineKeysetSortArmInterface', $e->getMessage());
            }
        }
    }

    #[Test]
    public function aKeysetWithoutASortHandlerRejectsACustomLevel(): void
    {
        $this->expectException(UnsupportedSort::class);

        (new DoctrineKeyset(new ClassMetadata(\stdClass::class), 'resource'))
            ->orderBy($this->builder(), [new KeysetColumn('value.cap', false, $this->sort())]);
    }

    private function boundValue(?string $type, string $wire): mixed
    {
        $columns = [new KeysetColumn('value.ends', false, $this->sort()), new KeysetColumn('id', false)];
        $builder = $this->builder();
        $this->keyset(new KeysetExpression('v.ends', $type))
            ->applyAfter($builder, $this->boundary(['value.ends' => $wire, 'id' => 1], $columns), $columns);

        $parameter = $this->parameter($builder, 'jsonapi_cursor_0');
        self::assertSame($type, $type === null ? null : $parameter->getType());

        return $parameter->getValue();
    }

    private function keyset(KeysetExpression $expression): DoctrineKeyset
    {
        return new DoctrineKeyset(
            new ClassMetadata(\stdClass::class),
            'resource',
            sorts: new DoctrineSortHandler([new RecordingKeysetArm($expression)]),
        );
    }

    /**
     * @param array<string, scalar|null> $values
     * @param list<KeysetColumn>         $columns
     */
    private function boundary(array $values, array $columns): CursorBoundary
    {
        $descending = [];
        foreach ($columns as $column) {
            $descending[$column->column] = $column->descending;
        }

        return new CursorBoundary($values, true, $descending);
    }

    private function sort(): KeysetSortInterface
    {
        return new class implements KeysetSortInterface {
            public function key(): string
            {
                return 'value.cap';
            }

            public function keysetValue(mixed $row): mixed
            {
                return null;
            }
        };
    }

    private function builder(): QueryBuilder
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getExpressionBuilder')->willReturn(new Expr());

        return new QueryBuilder($entityManager);
    }

    /**
     * @return list<string>
     */
    private function orderBy(QueryBuilder $builder): array
    {
        $parts = $builder->getDQLPart('orderBy');
        self::assertIsArray($parts);

        return \array_values(\array_map(static fn(mixed $part): string => $part instanceof \Stringable ? (string) $part : '', $parts));
    }

    private function where(QueryBuilder $builder): string
    {
        $where = $builder->getDQLPart('where');
        self::assertInstanceOf(\Stringable::class, $where);

        return (string) $where;
    }

    private function parameter(QueryBuilder $builder, string $name): Parameter
    {
        $parameter = $builder->getParameter($name);
        self::assertInstanceOf(Parameter::class, $parameter);

        return $parameter;
    }
}

/**
 * Hands back a fixed expression and records how it was asked.
 */
final class RecordingKeysetArm implements DoctrineKeysetSortArmInterface
{
    public int $calls = 0;

    public ?string $alias = null;

    public function __construct(private readonly KeysetExpression $expression) {}

    public function supports(SortInterface $sort): bool
    {
        return $sort instanceof KeysetSortInterface;
    }

    public function apply(SortInterface $sort, QueryBuilder $query, bool $descending, string $alias): void {}

    public function keysetExpression(SortInterface $sort, QueryBuilder $query, string $alias): KeysetExpression
    {
        ++$this->calls;
        $this->alias = $alias;

        return $this->expression;
    }
}
