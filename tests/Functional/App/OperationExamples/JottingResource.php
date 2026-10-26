<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OperationExamples;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The `notes` rows on the `counted` server again, paginated without a count, which
 * overrides the server's always-counting default.
 */
abstract class JottingResource extends AbstractResource
{
    public static string $type = 'jottings';

    public function fields(): array
    {
        return [
            Id::make()->example('6'),
            Str::make('title')->example('Milk'),
        ];
    }

    public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        return PagePaginator::make();
    }
}
