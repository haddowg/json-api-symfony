<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Email;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The shared **full** `members` declaration both multi-type kernels serve: the FULL
 * view of the {@see \haddowg\JsonApiBundle\Tests\Functional\App\MultiType\Member}
 * record — display name, email, and the private secret note.
 *
 * Its curated twin is {@see BasePublicMemberResource} (`public-members`), which
 * backs the SAME record/entity but declares only `displayName`. Both resources name
 * the same entity in the Doctrine kernel
 * ({@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineMemberResource}
 * vs {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrinePublicMemberResource}),
 * proving two JSON:API types may map to one entity (the bundle's type→entity map
 * only rejects one type → two entities). In the in-memory kernel two providers read
 * the same Member objects.
 *
 * A failure localizes to the provider, not the fixture — both kernels exercise this
 * one declaration with identical assertions.
 */
abstract class BaseMemberResource extends AbstractResource
{
    public static string $type = 'members';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('displayName')->required()->sortable(),
            // The PRIVATE columns the full view exposes — never declared on the
            // curated `public-members` view.
            Email::make('email')->required(),
            Str::make('secretNote'),
        ];
    }
}
