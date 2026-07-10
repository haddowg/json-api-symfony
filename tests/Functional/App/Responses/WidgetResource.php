<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Responses;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\Created;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\Ok;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\ResolvesCompletionRedirect;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Witnesses BOTH halves of the per-operation response feature: the read-path
 * {@see ResolvesCompletionRedirect} seam (a fetch-one for a "completed" widget, id `1`,
 * answers `303 See Other` to the produced resource; any other id renders the normal
 * `200`) AND the declared per-operation response sets projected into the OpenAPI
 * document — an async `202` create referencing the `jobs` document, a `204` update, a
 * `200` meta-only delete, and a `303` fetch-one completion. The projection is asserted
 * in {@see \haddowg\JsonApiBundle\Tests\Functional\ResponseDeclarationTest} against
 * `GET /docs.json`.
 */
#[AsJsonApiResource(
    create: [new Created(), new Accepted('jobs')],
    update: [new Ok(), new NoContent()],
    delete: [new NoContent(), new MetaResult()],
    fetchOne: [new Ok(), new SeeOther()],
)]
final class WidgetResource extends AbstractResource implements ResolvesCompletionRedirect
{
    public static string $type = 'widgets';

    /**
     * @return list<\haddowg\JsonApi\Resource\Field\FieldInterface>
     */
    public function fields(): array
    {
        return [
            Id::make()->build(),
            Str::make('name')->build(),
        ];
    }

    public function completionLocation(object $entity): ?string
    {
        \assert($entity instanceof Widget);

        return $entity->id === '1' ? 'https://example.test/widgets/done' : null;
    }
}
