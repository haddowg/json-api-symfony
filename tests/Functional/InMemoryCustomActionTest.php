<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Action\ActionInMemoryTestKernel;

/**
 * {@see CustomActionConformanceTestCase} against the in-memory provider/persister:
 * the witness for the Doctrine action path, running the same §10 action matrix over
 * the shared {@see \haddowg\JsonApiBundle\DataProvider\InMemoryStore} with no database.
 */
final class InMemoryCustomActionTest extends CustomActionConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ActionInMemoryTestKernel::class;
    }
}
