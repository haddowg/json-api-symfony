<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;

/**
 * Phase-0 stub of the reference Doctrine ORM read provider. It is wired only when
 * `doctrine/orm` is installed (the bundle guards its service registration with
 * `class_exists(EntityManagerInterface::class)`), because Doctrine is a
 * `require-dev` + `suggest` dependency, not a hard one.
 *
 * Phase 0 establishes the SPI seam: `fetchOne()` does a minimal `find()`, and
 * `fetchCollection()` returns the repository's full set. The
 * QueryBuilder-backed filter/sort/pagination (composing core's
 * `FilterHandlerInterface` / `SortHandlerInterface`) is fleshed out in Phase 1,
 * so this is a fill-in, not a new design.
 *
 * The `type → entity-class` mapping is a plain config map here; the richer
 * mapping (read from `#[AsJsonApiResource]` or Doctrine metadata) is deferred to
 * Phase 1.
 */
final class DoctrineDataProvider implements DataProviderInterface
{
    /**
     * @param array<string, class-string> $entityClassByType a `type → entity FQCN` map
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $entityClassByType,
    ) {}

    public function supports(string $type): bool
    {
        return isset($this->entityClassByType[$type]);
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->entityManager->find($this->entityClassFor($type), $id);
    }

    public function fetchCollection(string $type, QueryParameters $queryParameters): iterable
    {
        // Phase 0: no filters/sort/pagination yet — fleshed out in Phase 1 over
        // core's FilterHandlerInterface / SortHandlerInterface.
        return $this->entityManager->getRepository($this->entityClassFor($type))->findAll();
    }

    /**
     * @return class-string
     */
    private function entityClassFor(string $type): string
    {
        return $this->entityClassByType[$type]
            ?? throw new \LogicException(\sprintf('No Doctrine entity class is mapped for JSON:API type "%s".', $type));
    }
}
