<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\GateOrder;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksInterface;

/**
 * The `gateWidgets` resource: every hook method records that it ran, so a test can show
 * none did for a caller the security gates refuse. An admin may do anything; a user may
 * read widgets other than "secret", list them unless they hold `ROLE_NO_LIST`, and update
 * the widgets they own. `lockedPartner` only an admin may change, `adminPartner` only an
 * admin may read.
 */
abstract class BaseGateWidgetResource extends AbstractResource implements ResourceLifecycleHooksInterface
{
    public static string $type = 'gateWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Str::make('owner'),
            BelongsTo::make('partner', 'gateWidgets')->nullable(),
            BelongsTo::make('lockedPartner', 'gateWidgets')->storedAs('partner')->nullable()->security(mutate: "is_granted('ROLE_ADMIN')"),
            BelongsTo::make('adminPartner', 'gateWidgets')->storedAs('partner')->nullable()->security(read: "is_granted('ROLE_ADMIN')"),
            HasMany::make('children', 'gateWidgets'),
        ];
    }

    public function beforeSave(object $entity, bool $creating, HookContext $context): void
    {
        GateLog::$entries[] = 'beforeSave';
    }

    public function afterSave(object $entity, bool $creating, HookContext $context): ?DataResponse
    {
        GateLog::$entries[] = 'afterSave';

        return null;
    }

    public function beforeCreate(object $entity, HookContext $context): void
    {
        GateLog::$entries[] = 'beforeCreate';
    }

    public function afterCreate(object $entity, HookContext $context): ?DataResponse
    {
        GateLog::$entries[] = 'afterCreate';

        return null;
    }

    public function beforeUpdate(object $entity, object $original, HookContext $context): void
    {
        GateLog::$entries[] = 'beforeUpdate';
    }

    public function afterUpdate(object $entity, HookContext $context): ?DataResponse
    {
        GateLog::$entries[] = 'afterUpdate';

        return null;
    }

    public function beforeDelete(object $entity, HookContext $context): void
    {
        GateLog::$entries[] = 'beforeDelete';
    }

    public function afterDelete(object $entity, HookContext $context): DataResponse|NoContentResponse|null
    {
        GateLog::$entries[] = 'afterDelete';

        return null;
    }

    public function beforeRelationshipMutate(object $parent, HookContext $context): void
    {
        GateLog::$entries[] = 'beforeRelationshipMutate';
    }

    public function afterRelationshipMutate(object $parent, HookContext $context): ?IdentifierResponse
    {
        GateLog::$entries[] = 'afterRelationshipMutate';

        return null;
    }

    public function afterFetchOne(object $entity, HookContext $context): ?DataResponse
    {
        GateLog::$entries[] = 'afterFetchOne';

        return null;
    }

    public function afterFetchCollection(array $items, HookContext $context): ?DataResponse
    {
        GateLog::$entries[] = 'afterFetchCollection';

        return null;
    }
}
