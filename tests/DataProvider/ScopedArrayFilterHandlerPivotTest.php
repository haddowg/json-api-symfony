<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider;

use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApiBundle\DataProvider\ScopedArrayFilterHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The in-memory provider doesn't query its pivot rows, so a filter whose path reaches
 * one is unrecognised there, as a pivot column filter is.
 */
final class ScopedArrayFilterHandlerPivotTest extends TestCase
{
    #[Test]
    public function aPathReachingAPivotRowIsUnrecognised(): void
    {
        $types = [
            'albums' => [Id::make()->build(), HasMany::make('tracks', 'tracks')->build()],
            'tracks' => [Id::make()->build(), BelongsToMany::make('playlists', 'playlists')->fields(DateTime::make('addedAt'))->build()],
            'playlists' => [Id::make()->build()],
        ];
        $filter = (new ThroughPathResolver(static fn(string $type): ?array => $types[$type] ?? null))
            ->resolve(Where::make('addedAt', 'addedAt')->through('tracks.playlists.pivot')->build(), $types['albums']);

        $this->expectException(FilterParamUnrecognized::class);
        (new ScopedArrayFilterHandler(new ArrayFilterHandler()))->apply($filter, [], '2026-01-01');
    }
}
