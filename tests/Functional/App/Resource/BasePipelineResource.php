<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Tests\Functional\App\Composite\StopCode;

/**
 * The structured-list witness, shared by both providers: `steps` is a list of
 * {@see OneOf} elements picked by `operation`, and `stops` a list of {@see Obj}
 * elements whose `code` carries a custom constraint and whose `legs` nest a second
 * structured list. Each list stores as a single array, the shape a `json` column
 * round-trips; on Doctrine the `arrives` and `departsAt` children come back from
 * it as each date object's JSON dump.
 */
abstract class BasePipelineResource extends AbstractResource
{
    public static string $type = 'pipelines';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required(),
            ArrayList::make('steps')->nullable()->maxItems(3)->of(
                OneOf::make('step')->discriminator('operation')
                    ->variant('trim')
                    ->variant('find_replace', Str::make('find')->required(), Str::make('replace')->required()->maxLength(10)),
            ),
            ArrayList::make('stops')->nullable()->of(Obj::make('stop')->fields(
                Str::make('city')->required(),
                Str::make('code')->required()->constrain(new StopCode()),
                Integer::make('nights')->min(1),
                Date::make('arrives')->after(new \DateTimeImmutable('2020-01-01')),
                DateTime::make('departsAt'),
                ArrayList::make('legs')->of(Obj::make('leg')->fields(
                    Str::make('mode')->required()->in(['rail', 'road']),
                )),
            )),
        ];
    }
}
