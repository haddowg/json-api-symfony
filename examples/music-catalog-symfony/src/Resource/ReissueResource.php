<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Action\ReissueAlbum;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Model\Reissue;

/**
 * The `reissues` type: the request document of the album {@see ReissueAlbum} action,
 * hydrated into a {@see Reissue}.
 *
 * It is the **related-only witness**: `relatedOnly: true` gives it no endpoints of its
 * own, so nothing is routed under `/reissues` and the OpenAPI document gives it no
 * paths, only the components the action's request body references.
 */
#[AsJsonApiResource(relatedOnly: true)]
final class ReissueResource extends AbstractResource
{
    public static string $type = 'reissues';

    public function fields(): array
    {
        return [
            Id::make(),
            DateTime::make('releasedAt')->example('2001-10-01T00:00:00+00:00')
                ->required()
                ->before(static fn(): \DateTimeImmutable => new \DateTimeImmutable())
                ->useTimezone('UTC'),
        ];
    }
}
