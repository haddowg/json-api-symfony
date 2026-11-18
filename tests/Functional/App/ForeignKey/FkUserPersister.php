<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use haddowg\JsonApiBundle\DataPersister\AbstractDataPersister;

/**
 * The persister of the provider-served `fk-users`, which keep nothing of their own to
 * write: their relationships are written on the related rows through each foreign key.
 */
final class FkUserPersister extends AbstractDataPersister
{
    public function supports(string $type): bool
    {
        return $type === 'fk-users';
    }

    public function instantiate(string $type): object
    {
        return new FkUser();
    }

    public function create(string $type, object $entity): object
    {
        return $entity;
    }

    public function update(string $type, object $entity): object
    {
        return $entity;
    }

    public function delete(string $type, object $entity): void {}
}
