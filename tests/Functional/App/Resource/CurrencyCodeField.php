<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\OpenApi\EnumComponentCollector;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\Constraint\Required;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\FieldState;
use haddowg\JsonApi\Resource\Field\ProvidesFieldSchema;

/**
 * An application field that supplies its own schema, so core can't name its JSON type.
 * Required, it still refuses an empty value.
 */
final readonly class CurrencyCodeField extends AbstractFieldValue implements ProvidesFieldSchema
{
    public static function required(string $name): self
    {
        return new self(new FieldState($name, $name, constraints: [new Required()]));
    }

    public function projectFieldSchema(SchemaProjector $projector, bool $creating, ?EnumComponentCollector $collector): Schema
    {
        return Schema::ofType('string')->withFormat('currency-code');
    }
}
