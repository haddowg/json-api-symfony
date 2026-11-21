<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;

/**
 * A filter or sort reaching through a relation into a type the caller can't read is a
 * `403`, as the relation's related endpoint is: the relation's own read permission
 * decides when it declares one, and otherwise the related type's collection gate.
 * `reader` may read a widget but not list them; only an admin may read `adminPartner`.
 *
 * Seeded as {@see RelatedRowReadGateConformance}: widget 1's partner is 3 "classified".
 *
 * @phpstan-require-extends JsonApiFunctionalTestCase
 */
trait ThroughReadGateConformance
{
    #[Test]
    public function a_filter_through_a_relation_the_caller_may_not_read_is_forbidden(): void
    {
        self::assertSame(403, $this->throughGateStatus('user', '/securedWidgets?filter[viaAdminPartner]=classified'));
        self::assertSame(['1'], $this->throughGateIds('admin', '/securedWidgets?filter[viaAdminPartner]=classified'));
    }

    #[Test]
    public function a_sort_through_a_relation_the_caller_may_not_read_is_forbidden(): void
    {
        self::assertSame(403, $this->throughGateStatus('user', '/securedWidgets?sort=byAdminPartner'));
        self::assertSame(200, $this->throughGateStatus('admin', '/securedWidgets?sort=byAdminPartner'));
    }

    #[Test]
    public function the_related_types_collection_gate_decides_a_relation_with_no_permission_of_its_own(): void
    {
        // reader may follow exposedPartners, whose own permission admits them, but not
        // reach through partner into a type whose collection gate refuses them.
        self::assertSame(403, $this->throughGateStatus('reader', '/securedWidgets/1/exposedPartners?filter[viaPartner]=one'));
        self::assertSame([], $this->throughGateIds('reader', '/securedWidgets/1/exposedPartners?filter[viaExposedPartner]=one'));
        self::assertSame([], $this->throughGateIds('reader', '/securedWidgets/1/exposedPartners?filter[viaPublicPartner]=one'));
        self::assertSame(['1'], $this->throughGateIds('user', '/securedWidgets?filter[viaPartner]=classified'));
    }

    private function throughGateStatus(string $user, string $path): int
    {
        return $this->handle($path, 'GET', null, ['HTTP_AUTHORIZATION' => 'Bearer ' . $user])->getStatusCode();
    }

    /**
     * @return list<string>
     */
    private function throughGateIds(string $user, string $path): array
    {
        $response = $this->handle($path, 'GET', null, ['HTTP_AUTHORIZATION' => 'Bearer ' . $user]);
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $ids[] = \is_string($resource['id'] ?? null) ? $resource['id'] : '';
        }

        return $ids;
    }
}
