<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use PHPUnit\Framework\Attributes\Test;

/**
 * What another type's gates let a response show through a relation. A single related row
 * needs its type's read expression, the check `GET /{type}/{id}` makes, and only an admin
 * may read the widget named "classified". A to-many shows what its query returns, whole,
 * and only to a caller the related type's collection gate admits, the check `GET /{type}`
 * makes; `reader` may read a widget but not list them. Shared by both providers'
 * `securedWidgets` kernels.
 *
 * Seeded by each test class: widgets 1 "one", 2 "two", 3 "classified" and 4 "four".
 * Widget 1's partner is 3 and its partners are 2 and 3; widget 4's partner is 1.
 *
 * @phpstan-require-extends JsonApiFunctionalTestCase
 */
trait RelatedRowReadGateConformance
{
    #[Test]
    public function a_to_one_row_the_caller_may_not_read_is_left_out_of_linkage_and_included(): void
    {
        $document = $this->readGateDocument('user', '/securedWidgets/1?include=partner');
        self::assertNull($this->readGateData($this->readGateRelationship($document['data'] ?? null, 'partner')));
        self::assertSame([], $this->readGateIncluded($document));

        $admin = $this->readGateDocument('admin', '/securedWidgets/1?include=partner');
        self::assertSame(['type' => 'securedWidgets', 'id' => '3'], $this->readGateRelationship($admin['data'] ?? null, 'partner')['data'] ?? null);
        self::assertSame(['securedWidgets:3'], $this->readGateIncluded($admin));
    }

    #[Test]
    public function a_to_one_row_the_caller_may_not_read_is_left_out_of_an_included_resources_linkage(): void
    {
        $document = $this->readGateDocument('user', '/securedWidgets/4?include=partner.partner');

        self::assertSame(['securedWidgets:1'], $this->readGateIncluded($document));
        $included = $document['included'] ?? null;
        self::assertIsArray($included);
        self::assertNull($this->readGateData($this->readGateRelationship($included[0] ?? null, 'partner')));
    }

    #[Test]
    public function a_to_one_row_the_caller_may_not_read_is_absent_at_the_related_endpoints(): void
    {
        self::assertNull($this->readGateData($this->readGateDocument('user', '/securedWidgets/1/partner')));
        self::assertNull($this->readGateData($this->readGateDocument('user', '/securedWidgets/1/relationships/partner')));
        self::assertSame(['securedWidgets:3'], $this->readGateIdentities([$this->readGateDocument('admin', '/securedWidgets/1/partner')['data'] ?? null]));
    }

    #[Test]
    public function a_to_many_shows_every_row_its_query_returns_with_a_matching_total(): void
    {
        // The per-row gate would refuse "classified" to this caller, but a list holds what
        // its query returned: full pages, and a total that matches the rows paged through.
        $document = $this->readGateDocument('user', '/securedWidgets/1?include=partners');
        self::assertSame(
            [['type' => 'securedWidgets', 'id' => '2'], ['type' => 'securedWidgets', 'id' => '3']],
            $this->readGateRelationship($document['data'] ?? null, 'partners')['data'] ?? null,
        );
        self::assertSame(['securedWidgets:2', 'securedWidgets:3'], $this->readGateIncluded($document));

        $seen = [];
        $total = null;
        $next = '/securedWidgets/1/partners?page[number]=1&page[size]=1&withCount=_self_';
        while (\is_string($next)) {
            $page = $this->readGateDocument('user', $next, counts: true);
            $rows = $page['data'] ?? null;
            self::assertIsArray($rows);
            self::assertLessThanOrEqual(1, \count($rows));
            $seen = [...$seen, ...$this->readGateIdentities($rows)];
            $meta = $page['meta'] ?? null;
            self::assertIsArray($meta);
            $total = $meta['total'] ?? null;
            $links = $page['links'] ?? null;
            self::assertIsArray($links);
            $next = $this->readGateHref($links['next'] ?? null);
            if ($rows === []) {
                break;
            }
        }
        self::assertSame(['securedWidgets:2', 'securedWidgets:3'], $seen);
        self::assertSame(\count($seen), $total);

        $counted = $this->readGateDocument('user', '/securedWidgets/1?withCount=partners', counts: true);
        $meta = $this->readGateRelationship($counted['data'] ?? null, 'partners')['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertSame(\count($seen), $meta['total'] ?? null);
    }

    #[Test]
    public function a_to_many_whose_type_refuses_the_caller_its_collection_is_withheld(): void
    {
        $document = $this->readGateDocument('reader', '/securedWidgets/1?include=partners&withCount=partners', counts: true);
        $relationship = $this->readGateRelationship($document['data'] ?? null, 'partners');
        self::assertArrayNotHasKey('data', $relationship);
        self::assertArrayNotHasKey('meta', $relationship);
        self::assertArrayHasKey('links', $relationship);
        self::assertSame([], $this->readGateIncluded($document));

        foreach (['/securedWidgets', '/securedWidgets/1/partners', '/securedWidgets/1/relationships/partners'] as $path) {
            $response = $this->handle($path, 'GET', null, ['HTTP_AUTHORIZATION' => 'Bearer reader']);
            self::assertSame(403, $response->getStatusCode(), $path . ': ' . $response->getContent());
        }

        // The to-one still follows the per-row gate for the same caller.
        self::assertSame(['type' => 'securedWidgets', 'id' => '1'], $this->readGateRelationship(
            $this->readGateDocument('reader', '/securedWidgets/4')['data'] ?? null,
            'partner',
        )['data'] ?? null);
    }

    #[Test]
    public function a_flattened_attribute_through_a_row_the_caller_may_not_read_reads_null_and_refuses_a_write(): void
    {
        $user = $this->readGateAttributes('user', '/securedWidgets/1');
        self::assertArrayHasKey('partnerName', $user);
        self::assertNull($user['partnerName']);
        self::assertSame('classified', $this->readGateAttributes('admin', '/securedWidgets/1')['partnerName'] ?? null);

        $response = $this->handle('/securedWidgets/1', 'PATCH', [
            'data' => ['type' => 'securedWidgets', 'id' => '1', 'attributes' => ['partnerName' => 'renamed']],
        ], ['HTTP_AUTHORIZATION' => 'Bearer user']);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('classified', $this->readGateAttributes('admin', '/securedWidgets/3')['name'] ?? null);
    }

    #[Test]
    public function a_relation_permission_exposes_a_to_many_of_a_type_the_caller_may_not_list(): void
    {
        $document = $this->readGateDocument('reader', '/securedWidgets/1?include=exposedPartners&withCount=exposedPartners', counts: true);
        $relationship = $this->readGateRelationship($document['data'] ?? null, 'exposedPartners');
        self::assertSame([['type' => 'securedWidgets', 'id' => '2'], ['type' => 'securedWidgets', 'id' => '3']], $relationship['data'] ?? null);
        self::assertSame(2, \is_array($relationship['meta'] ?? null) ? $relationship['meta']['total'] ?? null : null);
        self::assertSame(['securedWidgets:2', 'securedWidgets:3'], $this->readGateIncluded($document));

        $related = $this->readGateDocument('reader', '/securedWidgets/1/exposedPartners?withCount=_self_', counts: true);
        self::assertSame(['securedWidgets:2', 'securedWidgets:3'], $this->readGateIdentities($related['data'] ?? null));
        self::assertSame(2, \is_array($related['meta'] ?? null) ? $related['meta']['total'] ?? null : null);
        self::assertSame(
            [['type' => 'securedWidgets', 'id' => '2'], ['type' => 'securedWidgets', 'id' => '3']],
            $this->readGateData($this->readGateDocument('reader', '/securedWidgets/1/relationships/exposedPartners')),
        );
    }

    #[Test]
    public function a_relation_permission_exposes_a_to_one_the_type_would_refuse(): void
    {
        $document = $this->readGateDocument('user', '/securedWidgets/1?include=exposedPartner');
        self::assertSame(['type' => 'securedWidgets', 'id' => '3'], $this->readGateData($this->readGateRelationship($document['data'] ?? null, 'exposedPartner')));
        self::assertSame(['securedWidgets:3'], $this->readGateIncluded($document));
        self::assertSame('classified', $this->readGateAttributes('user', '/securedWidgets/1')['exposedPartnerName'] ?? null);

        self::assertSame(['securedWidgets:3'], $this->readGateIdentities([$this->readGateData($this->readGateDocument('user', '/securedWidgets/1/exposedPartner'))]));
        self::assertSame(['type' => 'securedWidgets', 'id' => '3'], $this->readGateData($this->readGateDocument('user', '/securedWidgets/1/relationships/exposedPartner')));
    }

    #[Test]
    public function a_relation_permission_blocks_a_to_many_of_a_type_the_caller_may_list(): void
    {
        $document = $this->readGateDocument('user', '/securedWidgets/1?include=blockedPartners&withCount=blockedPartners', counts: true);
        $relationship = $this->readGateRelationship($document['data'] ?? null, 'blockedPartners');
        self::assertArrayNotHasKey('data', $relationship);
        self::assertArrayNotHasKey('meta', $relationship);
        self::assertSame([], $this->readGateIncluded($document));

        foreach (['/securedWidgets/1/blockedPartners', '/securedWidgets/1/relationships/blockedPartners'] as $path) {
            $response = $this->handle($path, 'GET', null, ['HTTP_AUTHORIZATION' => 'Bearer user']);
            self::assertSame(403, $response->getStatusCode(), $path . ': ' . $response->getContent());
        }

        $admin = $this->readGateDocument('admin', '/securedWidgets/1?include=blockedPartners');
        self::assertSame(['securedWidgets:2', 'securedWidgets:3'], $this->readGateIncluded($admin));
    }

    #[Test]
    public function a_relation_permission_blocks_a_to_one_and_the_flattened_attribute_through_it(): void
    {
        $user = $this->readGateAttributes('user', '/securedWidgets/4');
        self::assertArrayHasKey('adminPartnerName', $user);
        self::assertNull($user['adminPartnerName']);
        self::assertSame('one', $this->readGateAttributes('admin', '/securedWidgets/4')['adminPartnerName'] ?? null);

        $response = $this->handle('/securedWidgets/4', 'PATCH', [
            'data' => ['type' => 'securedWidgets', 'id' => '4', 'attributes' => ['adminPartnerName' => 'renamed']],
        ], ['HTTP_AUTHORIZATION' => 'Bearer user']);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('"code":"RELATED_ATTRIBUTE_OWNER_MISSING"', (string) $response->getContent());
        self::assertSame('one', $this->readGateAttributes('admin', '/securedWidgets/1')['name'] ?? null);
    }

    #[Test]
    public function a_bool_relation_permission_skips_the_types_collection_gate(): void
    {
        $document = $this->readGateDocument('reader', '/securedWidgets/1?include=publicPartners&withCount=publicPartners', counts: true);
        $relationship = $this->readGateRelationship($document['data'] ?? null, 'publicPartners');
        self::assertSame([['type' => 'securedWidgets', 'id' => '2'], ['type' => 'securedWidgets', 'id' => '3']], $relationship['data'] ?? null);
        self::assertSame(2, \is_array($relationship['meta'] ?? null) ? $relationship['meta']['total'] ?? null : null);
        self::assertSame(['securedWidgets:2', 'securedWidgets:3'], $this->readGateIdentities($this->readGateDocument('reader', '/securedWidgets/1/publicPartners')['data'] ?? null));
    }

    /**
     * @return array<mixed>
     */
    private function readGateAttributes(string $user, string $path): array
    {
        $data = $this->readGateDocument($user, $path)['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function readGateDocument(string $user, string $path, bool $counts = false): array
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $user];
        if ($counts) {
            $server['HTTP_ACCEPT'] = 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';
        }
        $response = $this->handle($path, 'GET', null, $server);
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function readGateRelationship(mixed $resource, string $name): array
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $relationship = $relationships[$name] ?? null;
        self::assertIsArray($relationship, $name . ' is rendered');

        /** @var array<string, mixed> $relationship */
        return $relationship;
    }

    /**
     * The `data` member, which must be present even when null.
     *
     * @param array<string, mixed> $document
     */
    private function readGateData(array $document): mixed
    {
        self::assertArrayHasKey('data', $document);

        return $document['data'];
    }

    /**
     * The path and query of a pagination link, or null when there is none.
     */
    private function readGateHref(mixed $link): ?string
    {
        $href = \is_array($link) ? $link['href'] ?? null : $link;
        if (!\is_string($href)) {
            return null;
        }
        $parts = \parse_url($href);

        return ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function readGateIncluded(array $document): array
    {
        return $this->readGateIdentities($document['included'] ?? []);
    }

    /**
     * `type:id` of each resource, sorted.
     *
     * @return list<string>
     */
    private function readGateIdentities(mixed $resources): array
    {
        self::assertIsArray($resources);
        $identities = [];
        foreach ($resources as $resource) {
            self::assertIsArray($resource);
            $identities[] = \sprintf('%s:%s', \is_string($resource['type'] ?? null) ? $resource['type'] : '', \is_string($resource['id'] ?? null) ? $resource['id'] : '');
        }
        \sort($identities);

        return $identities;
    }
}
