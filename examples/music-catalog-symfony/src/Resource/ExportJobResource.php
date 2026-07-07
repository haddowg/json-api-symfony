<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\OpenApi\Metadata\Ok;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\ResolvesCompletionRedirect;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Model\ExportJob;

/**
 * The `export-jobs` type — the read-only pollable job resource that closes the JSON:API
 * asynchronous-processing loop. `fetchOne: [new Ok(), new SeeOther()]` advertises both a
 * `200` (the job status, while it is still `processing`) and a `303 See Other` (once
 * `completed`, redirecting to the produced `catalog-exports` resource). The `303` is
 * driven at runtime by {@see ResolvesCompletionRedirect::completionLocation()}, which
 * returns the produced export's URL only when the job has completed. `readOnly` exposes
 * just the two fetch operations; `exportId` is internal state, not a declared field.
 */
#[AsJsonApiResource(
    readOnly: true,
    fetchOne: [new Ok(), new SeeOther()],
)]
final class ExportJobResource extends AbstractResource implements ResolvesCompletionRedirect
{
    public static string $type = 'export-jobs';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('state')->readOnly(),
        ];
    }

    public function completionLocation(object $entity): ?string
    {
        \assert($entity instanceof ExportJob);

        return $entity->state === 'completed'
            ? '/catalog-exports/' . $entity->exportId
            : null;
    }
}
