<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\AdditionalPropertiesBuilder;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Hook\InstantiatesEntityInterface;

/**
 * The users again, whose join rows take a `rank` through an open pivot, kept in each row's
 * position: `tagged` declares its link row fields, and `looseTagged` reads and writes the
 * same rows through closures and declares nothing but the entry.
 */
final class MtmTagAccountResource extends AbstractResource implements InstantiatesEntityInterface
{
    public static string $type = 'tag-accounts';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            MorphToMany::make('tagged', ['agencies', 'advertisers', 'publishers'])
                ->storedAs('userCompanies')
                ->through(MtmUserCompany::class)
                ->identifiedByRows(owner: 'user', type: 'companyType', id: 'companyId')
                ->fields(self::rank()),
            MorphToMany::make('looseTagged', ['agencies', 'advertisers', 'publishers'])
                ->identifiedBy(static fn(mixed $user): array => $user instanceof MtmUser
                    ? \array_values(\array_map(static fn(MtmUserCompany $row): array => [$row->companyType, $row->companyId, $row], $user->userCompanies->toArray()))
                    : [])
                ->fields(self::rank())
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

    private static function rank(): AdditionalPropertiesBuilder
    {
        return AdditionalProperties::make()->of(Integer::make('*'))
            ->fillUsing(static function (MtmUserCompany $row, array $members): MtmUserCompany {
                $rank = $members['rank'] ?? null;
                $row->position = \is_int($rank) ? $rank : null;

                return $row;
            });
    }
}
