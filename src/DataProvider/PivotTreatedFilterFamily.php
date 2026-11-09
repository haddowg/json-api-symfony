<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Filter\AcceptsList;
use haddowg\JsonApi\Resource\Filter\FilterFamilyInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\ListValue;

/**
 * A relation's filter family whose resolved filters get the pivot treatment an exact
 * relation filter gets in {@see RelationCriteriaFactory}: the pivot value cast where
 * pivot fields are queryable, or rejection where they are stripped.
 *
 * @internal
 */
final readonly class PivotTreatedFilterFamily implements FilterFamilyInterface, AcceptsList
{
    /**
     * @param \Closure(FilterInterface): ?FilterInterface $treat
     */
    public function __construct(
        private FilterFamilyInterface $family,
        private \Closure $treat,
    ) {}

    public function key(): string
    {
        return $this->family->key();
    }

    public function constraints(): array
    {
        return $this->family->constraints();
    }

    public function suffixPattern(): string
    {
        return $this->family->suffixPattern();
    }

    public function listValue(): ?ListValue
    {
        return $this->family instanceof AcceptsList ? $this->family->listValue() : null;
    }

    public function resolve(string $suffix, JsonApiRequestInterface $request): ?FilterInterface
    {
        $filter = $this->family->resolve($suffix, $request);

        return $filter === null ? null : ($this->treat)($filter);
    }
}
