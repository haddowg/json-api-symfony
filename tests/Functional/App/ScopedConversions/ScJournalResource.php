<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ScopedConversions;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\StrBuilder;

/**
 * A journal whose `mood` converts to the enum only on a create and whose `name` is
 * upper-cased only on an update, each declared inside a scope. Its `marks` keep a `tone`
 * on each link row that converts to the enum only on a new row. A hook records what each
 * write's `mood` arrived as.
 */
class ScJournalResource extends AbstractResource
{
    public static string $type = 'sc-journals';

    /**
     * The PHP type of each `mood` written, in write order.
     *
     * @var list<string>
     */
    public static array $moods = [];

    public function fields(): array
    {
        return [
            Id::make()->allowClientId(),
            Str::make('name')->onUpdate(static fn(StrBuilder $field) => $field->deserializeUsing(static fn(mixed $value): string => \strtoupper(\is_string($value) ? $value : ''))),
            Str::make('mood')
                ->onCreate(static fn(StrBuilder $field) => $field->enum(ScMood::class))
                ->fillUsing(static function (mixed $journal, ScMood|string $mood): mixed {
                    self::$moods[] = \get_debug_type($mood);

                    return $journal;
                }),
            MorphToMany::make('marks', ['sc-journals'])
                ->identifiedBy(static fn(mixed $journal): array => ScMarks::of($journal))
                ->fields(Str::make('tone')->nullable()->onCreate(static fn(StrBuilder $field) => $field->enum(ScMood::class)))
                ->fillUsing(static function (mixed $journal, mixed $linkage, array $data): mixed {
                    \assert($linkage instanceof ToManyRelationship);
                    $mode = $data['mode'] ?? Mode::Replace;
                    \assert($mode instanceof Mode);
                    ScMarks::fill($journal, $linkage, $mode);

                    return $journal;
                }),
        ];
    }
}
