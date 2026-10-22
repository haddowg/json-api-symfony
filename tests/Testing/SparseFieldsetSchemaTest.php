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
    public function leavesTypesWithoutAFieldsetAndTheOriginalDocumentAlone(): void
    {
        $document = $this->document();
        $relaxed = SparseFieldsetSchema::relax($document, ['artists' => []]);

        self::assertSame(['title', 'year'], $relaxed->components->schemas->AlbumsAttributes->required);
        self::assertSame(['name'], $document->components->schemas->ArtistsAttributes->required);
    }

    private function document(): \stdClass
    {
        $json = <<<'JSON'
            {
              "components": {
                "schemas": {
                  "AlbumsAttributes": {"type": "object", "required": ["title", "year"]},
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
