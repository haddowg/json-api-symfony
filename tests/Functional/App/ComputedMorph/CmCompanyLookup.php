<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph;

/**
 * Finds a company by type and id with no scope applied, so the relation's closure
 * returns hidden rows too and only the bundle's narrowing can drop them.
 */
interface CmCompanyLookup
{
    public function find(string $type, int $id): ?object;
}
