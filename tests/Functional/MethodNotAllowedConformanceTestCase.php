<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * A method a JSON:API path does not accept. The router refuses it before any route
 * matches, and the answer is still a JSON:API `405` naming the accepted methods in its
 * `Allow` header, while a path that is no JSON:API endpoint keeps Symfony's own `405`.
 * The `agencies` are read-only, the `advertisers` writable with a read-only relation, and
 * the server is mounted under `/api/v5` with an empty `base_uri`. Agency 1 and advertiser
 * 1 are seeded.
 */
abstract class MethodNotAllowedConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:errors')]
    public function aRelationshipWriteToAReadOnlyTypeIsAJsonApi405(): void
    {
        $response = $this->handle('/api/v5/agencies/1/relationships/advertisers', 'POST', [
            'data' => [['type' => 'advertisers', 'id' => '1']],
        ]);

        $this->assertMethodNotAllowed($response, 'GET');
    }

    #[Test]
    #[Group('spec:errors')]
    public function anUpdateOfAReadOnlyTypeIsAJsonApi405(): void
    {
        $response = $this->handle('/api/v5/agencies/1', 'PATCH', [
            'data' => ['type' => 'agencies', 'id' => '1', 'attributes' => ['name' => 'Renamed']],
        ]);

        $this->assertMethodNotAllowed($response, 'GET');
        self::assertSame(
            'The method PATCH is not allowed on this path, which accepts GET.',
            $this->firstError($response)['detail'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:errors')]
    public function everyOtherWriteToAReadOnlyTypeIsAJsonApi405(): void
    {
        $this->assertMethodNotAllowed($this->handle('/api/v5/agencies/1', 'DELETE'), 'GET');
        $this->assertMethodNotAllowed($this->handle('/api/v5/agencies', 'POST', [
            'data' => ['type' => 'agencies', 'attributes' => ['name' => 'New']],
        ]), 'GET');
    }

    #[Test]
    #[Group('spec:errors')]
    public function aWritableTypesPathListsEveryMethodItAccepts(): void
    {
        $this->assertMethodNotAllowed($this->handle('/api/v5/advertisers', 'PATCH'), 'GET, POST');
    }

    #[Test]
    #[Group('spec:errors')]
    public function aReadOnlyRelationOnAWritableTypeIsStillRefusedByTheHandler(): void
    {
        $response = $this->handle('/api/v5/advertisers/1/relationships/agencies', 'POST', [
            'data' => [['type' => 'agencies', 'id' => '1']],
        ]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        self::assertSame('ADDITION_PROHIBITED', $this->firstError($response)['code'] ?? null);
    }

    #[Test]
    #[Group('spec:errors')]
    public function theReadsStillServe(): void
    {
        $response = $this->handle('/api/v5/agencies/1');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function theMethodIsRefusedBeforeTheAcceptHeaderIsNegotiated(): void
    {
        $response = $this->handle('/api/v5/agencies/1', 'DELETE', extraServer: ['HTTP_ACCEPT' => 'text/html']);

        $this->assertMethodNotAllowed($response, 'GET');
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aMethodTheAtomicEndpointDoesNotAcceptDeclaresTheExtension(): void
    {
        $response = $this->handle('/api/v5/operations', 'GET', extraServer: [
            'HTTP_ACCEPT' => 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"',
        ]);

        self::assertSame(405, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('POST', $response->headers->get('Allow'));
        self::assertSame('application/vnd.api+json; ext="' . AtomicExtension::URI . '"', $response->headers->get('Content-Type'));
        self::assertSame('METHOD_NOT_ALLOWED', $this->firstError($response)['code'] ?? null);
    }

    #[Test]
    public function aPlainRouteUnderTheSamePrefixKeepsSymfonysOwn405(): void
    {
        $response = $this->handle('/api/v5/status', 'POST');

        self::assertSame(405, $response->getStatusCode());
        self::assertStringNotContainsString('application/vnd.api+json', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function aPlainRouteOutsideTheMountKeepsSymfonysOwn405(): void
    {
        $response = $this->handle('/health', 'DELETE');

        self::assertSame(405, $response->getStatusCode());
        self::assertStringNotContainsString('application/vnd.api+json', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function theDocumentCataloguesTheCodeButNoOperationLists405(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer('default')->toArray();

        $components = $document['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        self::assertArrayHasKey('MethodNotAllowedError', $schemas);

        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);
        foreach ($paths as $item) {
            self::assertIsArray($item);
            foreach ($item as $operation) {
                if (\is_array($operation) && \is_array($operation['responses'] ?? null)) {
                    self::assertArrayNotHasKey('405', $operation['responses']);
                }
            }
        }
    }

    private function assertMethodNotAllowed(Response $response, string $allow): void
    {
        self::assertSame(405, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        self::assertSame($allow, $response->headers->get('Allow'));

        $error = $this->firstError($response);
        self::assertSame('METHOD_NOT_ALLOWED', $error['code'] ?? null);
        self::assertSame('405', $error['status'] ?? null);
        self::assertSame('Method Not Allowed', $error['title'] ?? null);
    }

    /**
     * @return array<mixed>
     */
    private function firstError(Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertCount(1, $errors);
        self::assertIsArray($errors[0]);

        return $errors[0];
    }
}
