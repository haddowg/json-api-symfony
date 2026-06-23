<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * The convenience on-ramp for a custom {@see DataPersisterInterface}: an abstract base
 * that leaves the irreducible write core — {@see supports()}, {@see instantiate()},
 * {@see create()}, {@see update()}, {@see delete()} — abstract, and supplies a default
 * {@see mutateRelationship()} a persister that never serves the
 * `…/relationships/{rel}` write endpoints (nor embeds relationships in whole-resource
 * writes) does not have to hand-stub.
 *
 * The handler calls {@see mutateRelationship()} *unconditionally* for a relationship
 * write, so the default **throws** a clear {@see \LogicException} rather than silently
 * succeeding: a persister that only does whole-resource create/update/delete simply
 * never triggers it; one that exposes a writable relationship must override it (and
 * resolve the linkage ids to the related objects/references its storage uses). It is
 * the write-side analogue of core's `ResourceLifecycleHooksTrait` — convenience for the
 * common case, override for the rest.
 */
abstract class AbstractDataPersister implements DataPersisterInterface
{
    abstract public function supports(string $type): bool;

    abstract public function instantiate(string $type): object;

    abstract public function create(string $type, object $entity): object;

    abstract public function update(string $type, object $entity): object;

    abstract public function delete(string $type, object $entity): void;

    /**
     * Default: **throws** {@see \LogicException} — this persister declares no support for
     * relationship mutation. Override to apply a `…/relationships/{rel}` write (or a
     * relationship embedded in a whole-resource write): resolve the `$linkage`'s
     * resource-identifier ids to the related objects/references your storage uses and
     * mutate `$relation`'s association on `$entity` under `$mode`.
     */
    public function mutateRelationship(
        string $type,
        object $entity,
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        bool $flush = true,
    ): object {
        throw new \LogicException(\sprintf(
            'The "%s" persister does not support relationship mutation; override mutateRelationship() to support …/relationships/… writes.',
            $type,
        ));
    }
}
