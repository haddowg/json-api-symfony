<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The JSON type witness, shared by both providers: a field of every kind, at the top
 * level and nested, so a value of the wrong JSON type is refused at its pointer rather
 * than cast. `options`, `rows` and `mapping` reproduce the shapes the type check was
 * first reported against.
 */
abstract class BaseSubmissionResource extends AbstractResource
{
    public static string $type = 'submissions';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required()->maxLength(20),
            Integer::make('count')->nullable()->min(0),
            Decimal::make('ratio')->nullable()->max(10),
            Boolean::make('flag')->nullable(),
            Date::make('day')->nullable(),
            DateTime::make('at')->nullable(),
            Str::make('kind')->nullable()->enum(SubmissionKind::class),
            ArrayList::make('tags')->nullable()->maxItems(5),
            Obj::make('options')->nullable()->fields(
                Boolean::make('notifyPublishers'),
                Str::make('note')->nullable()->maxLength(20),
            ),
            ArrayList::make('rows')->nullable()->of(
                Obj::make('row')
                    ->fields(Str::make('Key')->required()->maxLength(20))
                    ->additionalProperties(Str::make('*')->maxLength(20)),
            ),
            Obj::make('mapping')->nullable()->fields(
                ArrayList::make('entries')->of(
                    Obj::make('entry')->fields(Obj::make('target')->fields(Str::make('name')->maxLength(40))),
                ),
            ),
            OneOf::make('block')->nullable()->discriminator('kind')
                ->variant('heading', Str::make('text')->required()->maxLength(40), Integer::make('level')->min(1)->max(6)),
        ];
    }
}
