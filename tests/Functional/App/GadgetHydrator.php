<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A custom hydrator for the `gadget` type, proving the generic engine drives
 * writes through a registered override (ADR 0023). It prefixes the incoming
 * `name` (a distinguishable transform) and assigns an id on create, and takes a
 * constructor dependency (the prefix), so the test passing proves it was
 * container-resolved with its dependency.
 */
final class GadgetHydrator implements HydratorInterface
{
    public function __construct(private readonly string $prefix) {}

    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        \assert($domainObject instanceof Gadget);

        $data = $request->getResource();
        $attributes = \is_array($data) && \is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $name = $attributes['name'] ?? '';

        $domainObject->name = $this->prefix . (\is_string($name) ? $name : '');
        if ($domainObject->id === '') {
            $domainObject->id = 'gadget-' . \bin2hex(\random_bytes(4));
        }

        return $domainObject;
    }
}
