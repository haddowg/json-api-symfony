<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The shared **curated** `public-members` declaration both multi-type kernels serve:
 * a strictly narrower view of the SAME
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\MultiType\Member} record the
 * full {@see BaseMemberResource} (`members`) exposes — display name only. The
 * private `email` / `secretNote` columns are simply not declared here, so no sparse
 * fieldset or include can resurface them; the curation IS the field inventory.
 *
 * This is the second resource type backed by the one entity. It is also the relation
 * target the `posts` resource declares (`BelongsTo::make('author', 'public-members')`):
 * a monomorphic relation renders its targets as the declared type, so a post's author
 * is identified as `public-members` even though the same Member is also a `members`.
 */
abstract class BasePublicMemberResource extends AbstractResource
{
    public static string $type = 'public-members';

    public function fields(): array
    {
        return [
            Id::make(),
            // The ONLY public attribute; email / secretNote are deliberately omitted.
            Str::make('displayName')->required()->sortable(),
        ];
    }
}
