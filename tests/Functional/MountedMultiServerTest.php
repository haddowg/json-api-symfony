<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\MountedMultiServerTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Two servers with an empty `base_uri`, mounted apart: `default` under `/api/v5`
 * and `admin` under `/admin`. Each route records its own server's mount, so a type
 * shared by both servers links to whichever mount the request came through.
 */
final class MountedMultiServerTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return MountedMultiServerTestKernel::class;
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function theDefaultServerLinksUnderItsMount(): void
    {
        $document = $this->fetch('/api/v5/public-widgets/p1');

        self::assertSame(['self' => ['href' => 'http://localhost/api/v5/public-widgets/p1']], $document['links'] ?? null);
        self::assertSame(
            'http://localhost/api/v5/public-widgets/p1/relationships/related',
            $this->relationshipSelf($document, 'related'),
        );
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function theAdminServerLinksUnderItsMount(): void
    {
        $document = $this->fetch('/admin/admin-widgets/a1');

        self::assertSame(['self' => ['href' => 'http://localhost/admin/admin-widgets/a1']], $document['links'] ?? null);
        self::assertSame(
            'http://localhost/admin/admin-widgets/a1/relationships/related',
            $this->relationshipSelf($document, 'related'),
        );
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function aSharedTypeLinksUnderTheMountItWasRequestedThrough(): void
    {
        self::assertSame(
            'http://localhost/api/v5/shared-widgets/s1/relationships/related',
            $this->relationshipSelf($this->fetch('/api/v5/shared-widgets/s1'), 'related'),
        );
        self::assertSame(
            'http://localhost/admin/shared-widgets/s1/relationships/related',
            $this->relationshipSelf($this->fetch('/admin/shared-widgets/s1'), 'related'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $uri): array
    {
        $response = $this->handle($uri);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array<string, mixed> */
        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function relationshipSelf(array $document, string $relationship): mixed
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $relation = $relationships[$relationship] ?? null;
        self::assertIsArray($relation);
        $links = $relation['links'] ?? null;
        self::assertIsArray($links);

        self::assertIsArray($links['self'] ?? null);

        return $links['self']['href'] ?? null;
    }

    #[Test]
    #[Group('spec:openapi')]
    public function eachServersOpenApiDocumentAdvertisesItsOwnMount(): void
    {
        self::assertSame([['url' => '/api/v5']], $this->openApi()['servers'] ?? null);
        self::assertSame([['url' => '/admin']], $this->openApi('admin')['servers'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function openApi(?string $server = null): array
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);

        return $factory->forServer($server)->toArray();
    }
}
