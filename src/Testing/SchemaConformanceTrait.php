<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Testing;

use haddowg\JsonApi\OpenApi\ComponentNaming;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The round-trip conformance guarantee (design §8, D11/G6 — bundle ADR 0081): assert a
 * **real** API response validates against the **generated** OpenAPI component schema for
 * its type — proving the document the bundle produces actually describes the responses
 * the bundle serves.
 *
 * Drop it into any {@see KernelTestCase}-based functional test (the bundle's own
 * conformance suite uses it across the in-memory and Doctrine kernels; an app's test
 * suite uses it the same way). It builds the document once per server via the bundle's
 * {@see DocumentFactory} — the same factory the warmer, the controller and the CLI use,
 * so the validated schema is byte-for-byte the served one — caches it, then validates the
 * response body against the chosen envelope component's internal `$ref`.
 *
 * Validation runs over `opis/json-schema` (a bundle `require-dev`), which implements the
 * JSON Schema 2020-12 dialect the projection targets **natively** — so no meta-schema
 * needs registering and the helper is fully offline. The component schemas are reached as
 * internal `#/components/schemas/<Component>` pointers within the one registered document,
 * which opis resolves.
 *
 * **Sparse fieldsets.** The read schemas require every member a full response carries.
 * When the request asked for `fields[<type>]`, members outside that fieldset are not
 * required for that type, in primary data and in `included` alike; members inside it
 * still are. Pass the browser that made the request in place of the response and the
 * fieldsets are read from its last request, or pass them explicitly as `$fieldsets`.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait SchemaConformanceTrait
{
    /** @var array<string, \stdClass> the built document per server, registered under {@see documentId()} */
    private array $generatedDocuments = [];

    /**
     * Assert `$response`'s JSON body validates against the generated component schema for
     * `$type`'s `$kind` document.
     *
     * @param KernelBrowser|Response|string   $response     the browser that made the request (its last response is validated and its fieldsets are read from its last request), the HttpFoundation response (the bundle's `handle()` form) or a raw JSON body string
     * @param string                          $type         the JSON:API primary type (e.g. `articles`) — for a related/relationship kind this is the type the endpoint hangs off
     * @param SchemaDocumentKind              $kind         which document envelope the response carries
     * @param string|null                     $relationship the relation name — required for {@see SchemaDocumentKind::Relationship} / {@see SchemaDocumentKind::Related}
     * @param string|null                     $server       the JSON:API server whose document to validate against (the implicit `default` server when null)
     * @param array<mixed>|null               $fieldsets    the request's `fields` map (`['albums' => 'title,year']`); overrides any read from a browser
     */
    protected function assertResponseMatchesGeneratedSchema(
        KernelBrowser|Response|string $response,
        string $type,
        SchemaDocumentKind $kind,
        ?string $relationship = null,
        ?string $server = null,
        ?array $fieldsets = null,
    ): void {
        $component = $kind->componentName($type, $relationship);

        if ($response instanceof KernelBrowser) {
            $query = $response->getRequest()->query->all();
            $fieldsets ??= \is_array($query['fields'] ?? null) ? $query['fields'] : null;
            $body = (string) $response->getResponse()->getContent();
        } else {
            $body = $response instanceof Response ? (string) $response->getContent() : $response;
        }

        $this->assertBodyMatchesGeneratedComponent($body, $component, $server, $fieldsets);
    }

    /**
     * The lower-level seam: validate a raw JSON body against a **named** generated
     * component by its bare component name (e.g. `ArticlesResource`, a custom enum
     * component, or a per-relation `RelatedCollection`). The kind-based
     * {@see assertResponseMatchesGeneratedSchema()} is the ergonomic front door; this is
     * the escape hatch for a component the four standard kinds do not name.
     *
     * @param array<mixed>|null $fieldsets the request's `fields` map, when it asked for sparse fieldsets
     */
    protected function assertBodyMatchesGeneratedComponent(string $body, string $component, ?string $server = null, ?array $fieldsets = null): void
    {
        $failures = $this->validateBody($body, $component, $server, $fieldsets);

        self::assertNull(
            $failures,
            \sprintf(
                "The response does not validate against the generated \"%s\" component schema:\n%s",
                $component,
                $failures ?? '',
            ),
        );
    }

    /**
     * The boolean form: does `$body` validate against the named generated component? The
     * assertion helpers above are the front door; this is for a test that needs to prove
     * validation **fails** for a deliberately-invalid body (the conformance guarantee
     * having teeth), where an assertion would be inverted.
     *
     * @param array<mixed>|null $fieldsets the request's `fields` map, when it asked for sparse fieldsets
     */
    protected function bodyMatchesGeneratedComponent(string $body, string $component, ?string $server = null, ?array $fieldsets = null): bool
    {
        return $this->validateBody($body, $component, $server, $fieldsets) === null;
    }

    /**
     * Validates the body against `$component`, then each `included` resource against its
     * own type's `<Base>Resource` component (the envelope's `included` schema only checks
     * `type`/`id`). An included type the document has no component for is skipped.
     *
     * @param array<mixed>|null $fieldsets
     *
     * @return string|null the readable failure detail, or null when the body is valid
     */
    private function validateBody(string $body, string $component, ?string $server, ?array $fieldsets): ?string
    {
        $decoded = \json_decode($body, false, 512, \JSON_THROW_ON_ERROR);

        $document = $this->generatedDocument($server);
        $normalized = SparseFieldsetSchema::normalize($fieldsets ?? []);
        if ($normalized !== []) {
            $document = SparseFieldsetSchema::relax($document, $normalized);
        }

        $validator = new Validator();
        $resolver = $validator->resolver();
        \assert($resolver !== null);
        $resolver->registerRaw($document, self::documentId());

        $result = $validator->validate($decoded, self::documentId() . ComponentNaming::schemaRef($component));
        if (!$result->isValid()) {
            return $this->describeErrors($result->error());
        }

        $included = $decoded instanceof \stdClass ? ($decoded->included ?? null) : null;
        if (!\is_array($included)) {
            return null;
        }

        $schemas = $document->components->schemas ?? null;
        $failures = [];
        foreach ($included as $index => $resource) {
            $type = $resource instanceof \stdClass ? ($resource->type ?? null) : null;
            if (!\is_string($type) || !$schemas instanceof \stdClass) {
                continue;
            }
            $resourceComponent = ComponentNaming::base($type) . 'Resource';
            if (!isset($schemas->{$resourceComponent})) {
                continue;
            }

            $result = $validator->validate($resource, self::documentId() . ComponentNaming::schemaRef($resourceComponent));
            if (!$result->isValid()) {
                $failures[] = \sprintf("/included/%d does not validate against \"%s\":\n%s", $index, $resourceComponent, $this->describeErrors($result->error()));
            }
        }

        return $failures === [] ? null : \implode("\n", $failures);
    }

    /**
     * The generated document for `$server` as the `stdClass` opis validates against —
     * built once per server (the build is pure) and cached. Decoding to `stdClass` (not
     * an assoc array) is required: an empty schema `{}` collapses to `[]` under an assoc
     * decode and breaks validation.
     */
    private function generatedDocument(?string $server): \stdClass
    {
        $key = $server ?? '';

        return $this->generatedDocuments[$key] ??= $this->factory()->forServer($server)->toJson();
    }

    private function factory(): DocumentFactory
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        \assert($factory instanceof DocumentFactory);

        return $factory;
    }

    private static function documentId(): string
    {
        return 'urn:haddowg:json-api:openapi:conformance';
    }

    /**
     * A readable, pointer-keyed rendering of the opis validation errors for the failure
     * message: each entry is `<data pointer>: <message>` so the assertion names exactly
     * which response member diverged from the schema.
     */
    private function describeErrors(?ValidationError $error): string
    {
        if ($error === null) {
            return '(no error detail)';
        }

        $formatter = new ErrorFormatter();
        $keyed = $formatter->formatKeyed(
            $error,
            static fn(ValidationError $e): string => $formatter->formatErrorMessage($e),
        );

        $lines = [];
        foreach ($keyed as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $lines[] = \sprintf('  %s: %s', $pointer === '' ? '/' : $pointer, $message);
            }
        }

        return $lines === [] ? '  (no pointer detail)' : \implode("\n", $lines);
    }
}
