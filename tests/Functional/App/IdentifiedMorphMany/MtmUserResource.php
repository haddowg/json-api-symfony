<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Hook\InstantiatesEntityInterface;

/**
 * `companies` reads its pairs off the join rows with identifiedBy() and writes them back
 * with fillUsing(), honouring the relationship endpoint's mode.
 */
final class MtmUserResource extends AbstractResource implements InstantiatesEntityInterface
{
    public static string $type = 'users';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            MorphToMany::make('companies', ['agencies', 'advertisers', 'publishers'])
                ->countable()
                ->identifiedBy(static fn(mixed $user): array => $user instanceof MtmUser ? $user->companyPairs() : [])
                ->fillUsing(static function (mixed $user, mixed $linkage, array $data): mixed {
                    \assert($user instanceof MtmUser && $linkage instanceof ToManyRelationship);
                    $sent = [];
                    foreach ($linkage->resourceIdentifiers as $identifier) {
                        $sent[$identifier->type . ':' . $identifier->id] = [$identifier->type, (int) $identifier->id];
                    }

                    $mode = $data['mode'] ?? Mode::Replace;
                    foreach ($user->userCompanies->toArray() as $row) {
                        $named = isset($sent[$row->key()]);
                        if (($mode === Mode::Replace && !$named) || ($mode === Mode::Remove && $named)) {
                            $user->userCompanies->removeElement($row);
                        }
                        unset($sent[$row->key()]);
                    }

                    if ($mode !== Mode::Remove) {
                        foreach ($sent as [$type, $id]) {
                            $user->addCompany($type, $id);
                        }
                    }

                    return $user;
                }),
        ];
    }

    public function instantiate(HookContext $context): object
    {
        return new MtmUser();
    }
}
