<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;
use haddowg\JsonApiBundle\DataPersister\Doctrine\DoctrineDataPersister;
use haddowg\JsonApiBundle\DataPersister\StoresDateTimesInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * An application persister that decorates the Doctrine persister and implements
 * StoresDateTimesInterface as it stood before StoresDecimalsInterface existed.
 */
final class DateTimeOnlyDecoratingPersister implements DataPersisterInterface, StoresDateTimesInterface, ResetInterface
{
    /**
     * @var list<string>
     */
    public static array $calls = [];

    public function __construct(private readonly DoctrineDataPersister $inner) {}

    public function supports(string $type): bool
    {
        return $this->inner->supports($type);
    }

    public function instantiate(string $type): object
    {
        return $this->inner->instantiate($type);
    }

    public function create(string $type, object $entity): object
    {
        self::$calls[] = 'create';

        return $this->inner->create($type, $entity);
    }

    public function update(string $type, object $entity): object
    {
        self::$calls[] = 'update';

        return $this->inner->update($type, $entity);
    }

    public function delete(string $type, object $entity): void
    {
        $this->inner->delete($type, $entity);
    }

    public function mutateRelationship(
        string $type,
        object $entity,
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        bool $flush = true,
    ): object {
        return $this->inner->mutateRelationship($type, $entity, $relation, $linkage, $mode, $flush);
    }

    public function dateTimeForColumn(object $entity, string $column, mixed $value): mixed
    {
        return $this->inner->dateTimeForColumn($entity, $column, $value);
    }

    public function holdsDateTimes(object $entity, string $column): bool
    {
        return $this->inner->holdsDateTimes($entity, $column);
    }

    public function reset(): void
    {
        $this->inner->reset();
    }
}
