<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;

/**
 * Relation-authorizer checks shared by both providers' `securedWidgets` kernels, where
 * widgets 1 and 2 are each other's partner and `adminPartner` reads the same column
 * as `partner` behind `security(read: "is_granted('ROLE_ADMIN')")`.
 *
 * @phpstan-require-extends JsonApiFunctionalTestCase
 */
trait RelationAuthorizerConformance
{
    #[Test]
    public function a_relation_the_caller_may_not_read_renders_links_but_no_data_on_the_primary_resource(): void
    {
        $data = $this->documentAs('user', '/securedWidgets/1')['data'] ?? null;

        $this->assertWithheld($this->relationshipOf($data, 'adminPartner'));
        self::assertSame(['type' => 'securedWidgets', 'id' => '2'], $this->relationshipOf($data, 'partner')['data'] ?? null);

        $admin = $this->documentAs('admin', '/securedWidgets/1')['data'] ?? null;
        self::assertSame(['type' => 'securedWidgets', 'id' => '2'], $this->relationshipOf($admin, 'adminPartner')['data'] ?? null);
    }

    #[Test]
    public function a_relation_the_caller_may_not_read_adds_nothing_to_included(): void
    {
        $document = $this->documentAs('user', '/securedWidgets/1?include=adminPartner');
        $this->assertWithheld($this->relationshipOf($document['data'] ?? null, 'adminPartner'));
        self::assertSame([], $this->includedOf($document));

        $admin = $this->documentAs('admin', '/securedWidgets/1?include=adminPartner');
        self::assertSame([['securedWidgets', '2']], $this->includedOf($admin));
    }

    #[Test]
    public function a_relation_the_caller_may_not_read_renders_no_data_on_an_included_resource(): void
    {
        $document = $this->documentAs('user', '/securedWidgets/1?include=partner');
        self::assertSame([['securedWidgets', '2']], $this->includedOf($document));

        $included = $document['included'] ?? null;
        self::assertIsArray($included);
        $this->assertWithheld($this->relationshipOf($included[0] ?? null, 'adminPartner'));
        self::assertSame(['type' => 'securedWidgets', 'id' => '1'], $this->relationshipOf($included[0] ?? null, 'partner')['data'] ?? null);
    }

    #[Test]
    public function a_relation_the_caller_may_not_read_renders_no_data_on_any_collection_member(): void
    {
        $members = $this->documentAs('user', '/securedWidgets')['data'] ?? null;
        self::assertIsArray($members);
        self::assertCount(2, $members);
        foreach ($members as $member) {
            $this->assertWithheld($this->relationshipOf($member, 'adminPartner'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function documentAs(string $user, string $path): array
    {
        $response = $this->handle($path, 'GET', null, ['HTTP_AUTHORIZATION' => 'Bearer ' . $user]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function relationshipOf(mixed $resource, string $name): array
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
     * @param array<string, mixed> $relationship
     */
    private function assertWithheld(array $relationship): void
    {
        self::assertArrayNotHasKey('data', $relationship);
        self::assertArrayNotHasKey('meta', $relationship);
        self::assertArrayHasKey('links', $relationship);
    }

    /**
     * The `[type, id]` of each included resource, or `[]` when there are none.
     *
     * @param array<string, mixed> $document
     *
     * @return list<array{mixed, mixed}>
     */
    private function includedOf(array $document): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        return \array_values(\array_map(
            static fn(mixed $resource): array => \is_array($resource) ? [$resource['type'] ?? null, $resource['id'] ?? null] : [null, null],
            $included,
        ));
    }
}
