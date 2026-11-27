<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Members are routed under `crew-members`, render no self link and link the `commend`
 * action, so a member rendered through a pivot decorator shows whether the decorator
 * keeps its URI type, its self-link toggle and the links the resolver contributes.
 */
class CpMemberResource extends AbstractResource
{
    public static string $type = 'cp-members';

    public static string $uriType = 'crew-members';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }

    public function emitsSelfLink(): bool
    {
        return false;
    }
}
