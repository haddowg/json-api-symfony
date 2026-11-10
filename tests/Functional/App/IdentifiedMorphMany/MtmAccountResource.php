<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Hook\InstantiatesEntityInterface;

/**
 * The users again as `accounts`, their companies declared by the link rows' fields, so
 * the data layer queries and writes the rows itself.
 */
final class MtmAccountResource extends AbstractResource implements InstantiatesEntityInterface
{
    public static string $type = 'accounts';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            MorphToMany::make('companies', ['agencies', 'advertisers', 'publishers'])
                ->countable()
                ->storedAs('userCompanies')
                ->through(MtmUserCompany::class)
                ->identifiedByRows(owner: 'user', type: 'companyType', id: 'companyId'),
        ];
    }

    public function instantiate(HookContext $context): object
    {
        return new MtmUser();
    }
}
