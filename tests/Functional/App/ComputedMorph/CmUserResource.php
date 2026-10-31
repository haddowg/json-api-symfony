<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Hook\InstantiatesEntityInterface;

/**
 * `companies` is computed from the join rows by extractUsing() and written back to
 * them by fillUsing(), honouring the relationship endpoint's mode.
 */
final class CmUserResource extends AbstractResource implements InstantiatesEntityInterface
{
    public static string $type = 'cm-users';

    public function __construct(private readonly CmCompanyLookup $companies) {}

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            MorphToMany::make('companies', ['cm-agencies', 'cm-advertisers', 'cm-publishers'])
                ->countable()
                ->extractUsing(fn(mixed $user): array => $user instanceof CmUser ? $this->resolve($user) : [])
                ->fillUsing(static function (mixed $user, mixed $linkage, array $data): mixed {
                    \assert($user instanceof CmUser && $linkage instanceof ToManyRelationship);
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
        return new CmUser();
    }

    /**
     * @return list<object>
     */
    private function resolve(CmUser $user): array
    {
        $members = [];
        foreach ($user->userCompanies as $row) {
            $member = $this->companies->find($row->companyType, $row->companyId);
            if ($member !== null) {
                $members[] = $member;
            }
        }

        return $members;
    }
}
