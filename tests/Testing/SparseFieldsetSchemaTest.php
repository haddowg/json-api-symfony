<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Testing;

use haddowg\JsonApiBundle\Testing\SparseFieldsetSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SparseFieldsetSchemaTest extends TestCase
{
    #[Test]
    public function normalizesCommaStringsAndLists(): void
    {
        self::assertSame(
            ['albums' => ['title', 'year'], 'artists' => [], 'tracks' => ['name']],
            SparseFieldsetSchema::normalize(['tracks' => ['name'], 'albums' => 'title, year', 'artists' => '', 0 => 'ignored']),
        );
    }

    #[Test]
    public function dropsMembersOutsideTheFieldsetFromRequiredAttributesAndRelationships(): void
    {
        $relaxed = SparseFieldsetSchema::relax($this->document(), ['albums' => ['title', 'artist']]);
        $schemas = $relaxed->components->schemas;

        self::assertSame(['title'], $schemas->AlbumsAttributes->required);
        self::assertSame(['artist'], $schemas->AlbumsRelationships->required);
        self::assertSame(['type', 'id'], $schemas->AlbumsResource->required);
    }

    #[Test]
    public function followsAnInlineRelationshipsSchemaAndDropsAnEmptyRequiredList(): void
    {
        $relaxed = SparseFieldsetSchema::relax($this->document(), ['artists' => []]);
        $schemas = $relaxed->components->schemas;

        self::assertObjectNotHasProperty('required', $schemas->ArtistsAttributes);
        self::assertObjectNotHasProperty('required', $schemas->ArtistsResource->properties->relationships);
    }

    #[Test]
    public function followsAReferenceWrappedInASingleEntryAllOf(): void
    {
        $relaxed = SparseFieldsetSchema::relax($this->document(), ['tracks' => ['title']]);

        self::assertSame(['title'], $relaxed->components->schemas->TracksAttributes->required);
    }

    #[Test]
    public function leavesTypesWithoutAFieldsetAndTheOriginalDocumentAlone(): void
    {
        $document = $this->document();
        $relaxed = SparseFieldsetSchema::relax($document, ['artists' => []]);

        self::assertSame(['title', 'year', 'releaseInfo', 'credits', 'dimensions'], $relaxed->components->schemas->AlbumsAttributes->required);
        self::assertSame(['name'], $document->components->schemas->ArtistsAttributes->required);
    }

    #[Test]
    public function aSelectedKeyKeepsItsAttributeRequiredAndNarrowsItsSchema(): void
    {
        $relaxed = SparseFieldsetSchema::relax($this->document(), ['albums' => ['title', 'releaseInfo.label', 'credits.producer']]);
        $attributes = $relaxed->components->schemas->AlbumsAttributes;

        self::assertSame(['title', 'releaseInfo', 'credits'], $attributes->required);

        $releaseInfo = $attributes->properties->releaseInfo;
        self::assertObjectNotHasProperty('required', $releaseInfo);
        self::assertSame(['label'], \array_keys((array) $releaseInfo->properties));
        self::assertSame(['label'], $releaseInfo->propertyNames->enum);
        self::assertSame(['object', 'null'], $releaseInfo->type);

        $credits = $attributes->properties->credits;
        self::assertSame(['producer'], $credits->propertyNames->enum);
        self::assertObjectNotHasProperty('minProperties', $credits);
        self::assertSame('string', $credits->additionalProperties->type);
    }

    #[Test]
    public function theWholeAttributeWinsOverItsKeys(): void
    {
        $relaxed = SparseFieldsetSchema::relax($this->document(), ['albums' => ['releaseInfo', 'releaseInfo.label']]);
        $releaseInfo = $relaxed->components->schemas->AlbumsAttributes->properties->releaseInfo;

        self::assertSame(['releaseInfo'], $relaxed->components->schemas->AlbumsAttributes->required);
        self::assertSame(['label', 'catalogueNumber'], $releaseInfo->required);
        self::assertObjectNotHasProperty('propertyNames', $releaseInfo);
    }

    #[Test]
    public function aReferencedComponentIsNarrowedInlineAndLeftWholeForItsOtherUses(): void
    {
        $relaxed = SparseFieldsetSchema::relax($this->document(), ['albums' => ['dimensions.width']]);
        $schemas = $relaxed->components->schemas;
        $dimensions = $schemas->AlbumsAttributes->properties->dimensions;

        $branch = $dimensions->anyOf[0];
        self::assertObjectNotHasProperty('$ref', $branch);
        self::assertObjectNotHasProperty('required', $branch);
        self::assertSame(['width'], \array_keys((array) $branch->properties));
        self::assertSame(['type' => 'null'], (array) $dimensions->anyOf[1]);
        self::assertSame(['width', 'height'], $schemas->Dimensions->required);
    }

    private function document(): \stdClass
    {
        $json = <<<'JSON'
            {
              "components": {
                "schemas": {
                  "AlbumsAttributes": {
                    "type": "object",
                    "required": ["title", "year", "releaseInfo", "credits", "dimensions"],
                    "properties": {
                      "releaseInfo": {
                        "type": ["object", "null"],
                        "properties": {"label": {"type": "string"}, "catalogueNumber": {"type": "string"}},
                        "required": ["label", "catalogueNumber"],
                        "additionalProperties": false
                      },
                      "credits": {"type": "object", "minProperties": 1, "additionalProperties": {"type": "string"}},
                      "dimensions": {"anyOf": [{"$ref": "#/components/schemas/Dimensions"}, {"type": "null"}]}
                    }
                  },
                  "Dimensions": {
                    "type": "object",
                    "properties": {"width": {"type": "integer"}, "height": {"type": "integer"}},
                    "required": ["width", "height"]
                  },
                  "AlbumsRelationships": {"type": "object", "required": ["artist", "tracks"]},
                  "AlbumsResource": {
                    "type": "object",
                    "required": ["type", "id", "attributes", "relationships"],
                    "properties": {
                      "attributes": {"$ref": "#/components/schemas/AlbumsAttributes"},
                      "relationships": {"$ref": "#/components/schemas/AlbumsRelationships"}
                    }
                  },
                  "ArtistsAttributes": {"type": "object", "required": ["name"]},
                  "TracksAttributes": {"type": "object", "required": ["title", "length"]},
                  "TracksResource": {
                    "type": "object",
                    "properties": {
                      "attributes": {"allOf": [{"$ref": "#/components/schemas/TracksAttributes"}], "example": {"title": "Intro"}}
                    }
                  },
                  "ArtistsResource": {
                    "type": "object",
                    "properties": {
                      "attributes": {"$ref": "#/components/schemas/ArtistsAttributes"},
                      "relationships": {"type": "object", "required": ["albums"]}
                    }
                  }
                }
              }
            }
            JSON;

        $document = \json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        \assert($document instanceof \stdClass);

        return $document;
    }
}
