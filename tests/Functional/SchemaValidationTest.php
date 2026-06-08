<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\SchemaValidationTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The optional opis structural linter (`json_api.schema_validation`) end to end:
 * a write body is validated against the JSON:API JSON Schema before dispatch, so
 * a structurally malformed document is rejected with `400` — distinct from the
 * Symfony Validator bridge's semantic `422`. With the toggle off (every other
 * kernel) this layer is absent.
 */
final class SchemaValidationTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return SchemaValidationTestKernel::class;
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function aResourceObjectWithADisallowedMemberIsRejectedWith400(): void
    {
        // `bogus` is not an allowed resource-object member; the JSON:API schema
        // rejects it. Core's lightweight top-level-member check does not, and the
        // hydrator would silently ignore it — so this is attributable to the linter.
        $response = $this->handle('/articles', 'POST', [
            'data' => [
                'type' => 'articles',
                'attributes' => ['title' => 'A valid title', 'category' => 'news'],
                'bogus' => 'nope',
            ],
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function aStructurallyValidDocumentPassesTheLinter(): void
    {
        $response = $this->handle('/articles', 'POST', [
            'data' => [
                'type' => 'articles',
                'attributes' => ['title' => 'A valid title', 'category' => 'news'],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
    }
}
