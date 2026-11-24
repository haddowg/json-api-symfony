<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereMorph;

abstract class ImNoteResource extends AbstractResource
{
    public const array FOREIGN_KEYS = ['int', 'str', 'uuid', 'ulid', 'guid', 'code', 'enc', 'prefix'];

    public static string $type = 'im-notes';

    public function fields(): array
    {
        $fields = [Id::make()->numeric(), Str::make('title')];
        foreach (self::FOREIGN_KEYS as $kind) {
            $fields[] = Str::make($kind . 'OwnerId')->hidden()->readOnly();
        }

        return [
            ...$fields,
            Str::make('subjectId')->hidden()->readOnly(),
            MorphTo::make('subject', ['im-posts', 'im-videos'])->identifiedByColumns(type: 'subjectType', id: 'subjectId')->nullable(),
            MorphTo::make('target', ['im-uuid-owners'])->identifiedByColumns(type: 'targetType', id: 'targetId')->nullable(),
            MorphTo::make('ref', ['im-guid-owners', 'im-uuid-owners', 'im-prefix-owners'])->identifiedByColumns(type: 'refType', id: 'refId')->nullable(),
        ];
    }

    public function filters(): array
    {
        $filters = [WhereMorph::make('target'), WhereMorph::make('ref')];
        foreach (self::FOREIGN_KEYS as $kind) {
            $filters[] = WhereIn::make($kind . 'Owner', $kind . 'OwnerId');
        }

        return $filters;
    }
}
