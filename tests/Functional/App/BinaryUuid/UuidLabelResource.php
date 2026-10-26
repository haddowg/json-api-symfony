<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: UuidLabelEntity::class)]
final class UuidLabelResource extends AbstractResource
{
    public static string $type = 'labels';

    public function __construct(private readonly ?IdEncoderInterface $idEncoder = null) {}

    public function fields(): array
    {
        $id = Id::make()->uuid();
        if ($this->idEncoder !== null) {
            $id->encodeUsing($this->idEncoder);
        }

        return [
            $id,
            Str::make('name')->sortable(),
        ];
    }
}
