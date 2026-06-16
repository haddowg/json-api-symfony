<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Supplies the declared relations of a JSON:API type that has no
 * {@see \haddowg\JsonApi\Resource\AbstractResource} (bundle ADR 0026). A class
 * implementing this and carrying {@see \haddowg\JsonApiBundle\Attribute\AsJsonApiRelations}
 * is held in the {@see RelationsRegistry}, keyed by type, and resolved lazily — its
 * {@see relations()} return runtime relation objects (not container-dumpable scalars).
 */
interface RelationsProviderInterface
{
    /**
     * @return list<RelationInterface>
     */
    public function relations(): array;
}
