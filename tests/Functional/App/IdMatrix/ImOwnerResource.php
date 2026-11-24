<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;

/**
 * An owner whose `notes` are the `im-notes` whose foreign key field of its id kind holds
 * its key.
 */
abstract class ImOwnerResource extends AbstractResource
{
    protected const string FOREIGN_KEY = '';

    public function fields(): array
    {
        $encoder = $this->encoder();

        return [
            $encoder === null ? Id::make() : Id::make()->encodeUsing($encoder),
            Str::make('name'),
            HasMany::make('notes', 'im-notes', static::FOREIGN_KEY)->withData()->countable(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereHas::make('hasNotes', 'notes'),
            WhereDoesntHave::make('noNotes', 'notes'),
        ];
    }

    protected function encoder(): ?IdEncoderInterface
    {
        return null;
    }
}
