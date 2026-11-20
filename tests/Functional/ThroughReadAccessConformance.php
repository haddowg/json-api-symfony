<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * A filter that reaches through a relation matches only rows the caller may read, on
 * both kernels. Seeded by {@see App\ThroughAccess\ThroughFixtures}: parcels are scoped to
 * their owner, secret tags to admins, vaults list and safes read only for admins, and
 * the `ledger` and `auditor` relations carry their own read permissions.
 *
 * @phpstan-require-extends JsonApiFunctionalTestCase
 */
trait ThroughReadAccessConformance
{
    #[Test]
    public function a_row_the_scope_hides_never_matches_through_a_hop(): void
    {
        // Parcel 2 is grace's, so ada can't find crate 1 through its label.
        self::assertSame([], $this->crateIds('ada', 'filter[parcelLabel]=banana'));
        self::assertSame(['1'], $this->crateIds('grace', 'filter[parcelLabel]=banana'));
        self::assertSame(['1'], $this->crateIds('admin', 'filter[parcelLabel]=banana'));
    }

    #[Test]
    public function a_caller_the_scope_does_not_affect_sees_the_same_rows(): void
    {
        self::assertSame(['1'], $this->crateIds('ada', 'filter[parcelLabel]=apple'));
        self::assertSame(['2'], $this->crateIds('ada', 'filter[parcelLabel]=cherry'));
        self::assertSame(['1'], $this->crateIds('ada', 'filter[name]=alpha'));
        self::assertSame(['1', '2', '3'], $this->crateIds('ada', ''));
        self::assertSame(['1'], $this->crateIds('admin', 'filter[vaultCode]=v1'));
        self::assertSame(['1'], $this->crateIds('admin', 'filter[safeCode]=s1'));
        self::assertSame(['1'], $this->crateIds('admin', 'filter[ledgerCode]=l1'));
    }

    #[Test]
    public function a_list_matches_only_through_rows_the_caller_may_read(): void
    {
        self::assertSame([], $this->crateIds('ada', 'filter[parcelLabels]=banana'));
        self::assertSame(['1', '2'], $this->crateIds('ada', 'filter[parcelLabels]=banana,apple,cherry'));
        self::assertSame(['1'], $this->crateIds('grace', 'filter[parcelLabels]=banana,apple,cherry'));
    }

    #[Test]
    public function a_path_ending_on_a_hidden_row_does_not_match_its_id(): void
    {
        self::assertSame([], $this->crateIds('ada', 'filter[parcel]=2'));
        self::assertSame(['1'], $this->crateIds('grace', 'filter[parcel]=2'));
    }

    #[Test]
    public function every_hop_of_a_longer_path_is_scoped(): void
    {
        // The hidden tag is secret, and parcel 1's red tag is ada's.
        self::assertSame([], $this->crateIds('ada', 'filter[tagName]=hidden'));
        self::assertSame([], $this->crateIds('grace', 'filter[tagName]=hidden'));
        self::assertSame(['1', '2'], $this->crateIds('admin', 'filter[tagName]=hidden'));
        self::assertSame(['1'], $this->crateIds('ada', 'filter[tagName]=red'));
        self::assertSame([], $this->crateIds('grace', 'filter[tagName]=red'));
    }

    #[Test]
    public function a_relationship_existence_filter_counts_only_rows_the_caller_may_read(): void
    {
        self::assertSame(['1', '2'], $this->crateIds('ada', 'filter[hasParcels]=1'));
        self::assertSame(['1'], $this->crateIds('grace', 'filter[hasParcels]=1'));
    }

    #[Test]
    public function a_filter_inside_a_group_is_scoped(): void
    {
        self::assertSame([], $this->crateIds('ada', 'filter[search]=banana'));
        self::assertSame(['1'], $this->crateIds('grace', 'filter[search]=banana'));
        self::assertSame(['2'], $this->crateIds('ada', 'filter[search]=beta'));
    }

    #[Test]
    public function a_filter_through_a_type_the_caller_may_not_list_is_forbidden(): void
    {
        $this->assertForbidden('ada', '/crates?filter[vaultCode]=v1');
    }

    #[Test]
    public function a_filter_through_a_type_the_caller_may_not_read_is_forbidden(): void
    {
        $this->assertForbidden('ada', '/crates?filter[safeCode]=s1');
    }

    #[Test]
    public function a_filter_through_a_relation_the_caller_may_not_read_is_forbidden(): void
    {
        $this->assertForbidden('ada', '/crates?filter[ledgerCode]=l1');
    }

    #[Test]
    public function a_relation_permission_that_reads_the_parent_forbids_the_filter_for_everyone(): void
    {
        $this->assertForbidden('ada', '/crates?filter[auditorCode]=l1');
        $this->assertForbidden('admin', '/crates?filter[auditorCode]=l1');
    }

    #[Test]
    public function a_forbidden_hop_inside_a_group_forbids_the_group(): void
    {
        $this->assertForbidden('ada', '/crates?filter[anyCode]=v1');
        self::assertSame(['1'], $this->crateIds('admin', 'filter[anyCode]=v1'));
    }

    #[Test]
    public function the_segment_names_its_own_relation_when_another_shares_its_storage(): void
    {
        // `sealedParcels` is admin-only and stored as `parcels`, but `parcels.label` names `parcels`.
        self::assertSame(['1'], $this->crateIds('ada', 'filter[parcelLabel]=apple'));
    }

    #[Test]
    public function a_related_read_filters_through_rows_the_caller_may_read(): void
    {
        self::assertSame([], $this->ids($this->document('grace', '/crates/1/parcels?filter[tagName]=hidden')['data'] ?? null));
        self::assertSame(['2'], $this->ids($this->document('admin', '/crates/1/parcels?filter[tagName]=hidden')['data'] ?? null));
        $this->assertForbidden('ada', '/crates/1/parcels?filter[crateVault]=v1');
    }

    #[Test]
    public function a_relationship_query_filters_through_rows_the_caller_may_read(): void
    {
        $profile = ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"'];

        $ada = $this->document('ada', '/crates/1?include=parcels&relatedQuery[parcels][filter][tagName]=red', $profile);
        self::assertSame(['1'], $this->ids($this->relationshipData($ada, 'parcels')));

        $grace = $this->document('grace', '/crates/1?include=parcels&relatedQuery[parcels][filter][tagName]=hidden', $profile);
        self::assertSame([], $this->ids($this->relationshipData($grace, 'parcels')));

        $admin = $this->document('admin', '/crates/1?include=parcels&relatedQuery[parcels][filter][tagName]=hidden', $profile);
        self::assertSame(['2'], $this->ids($this->relationshipData($admin, 'parcels')));

        $this->assertForbidden('ada', '/crates/1?include=parcels&relatedQuery[parcels][filter][crateVault]=v1', $profile);
    }

    #[Test]
    public function an_action_filter_matches_through_rows_the_caller_may_read(): void
    {
        self::assertSame([], $this->tally('ada', 'filter[parcelLabel]=banana'));
        self::assertSame(['1'], $this->tally('grace', 'filter[parcelLabel]=banana'));
        self::assertSame(['1'], $this->tally('admin', 'filter[vaultCode]=v1'));
        $this->assertForbidden('ada', '/crates/-actions/tally?filter[vaultCode]=v1', [], 'POST');
    }

    #[Test]
    public function a_morph_target_the_scope_hides_never_matches(): void
    {
        // Crate 1 is about grace's parcel 2, crate 2 about ada's parcel 3.
        self::assertSame(['2'], $this->crateIds('ada', 'filter[subject][type]=parcels'));
        self::assertSame(['1'], $this->crateIds('grace', 'filter[subject][type]=parcels'));
        self::assertSame(['1', '2'], $this->crateIds('admin', 'filter[subject][type]=parcels'));
        self::assertSame([], $this->crateIds('ada', 'filter[subject][type]=parcels&filter[subject][id]=2'));
        self::assertSame(['2'], $this->crateIds('ada', 'filter[subject][type]=parcels&filter[subject][id]=2,3'));
        self::assertSame(['1'], $this->crateIds('grace', 'filter[subject][type]=parcels&filter[subject][id][]=2'));
    }

    #[Test]
    public function a_morph_member_the_scope_hides_never_matches(): void
    {
        // Crate 1 is marked with ada's parcel 1, crate 2 with grace's parcel 2.
        self::assertSame(['1'], $this->crateIds('ada', 'filter[marked][type]=parcels'));
        self::assertSame(['2'], $this->crateIds('grace', 'filter[marked][type]=parcels'));
        self::assertSame(['1', '2'], $this->crateIds('admin', 'filter[marked][type]=parcels'));
        self::assertSame([], $this->crateIds('ada', 'filter[marked][type]=parcels&filter[marked][id]=2'));
    }

    #[Test]
    public function a_morph_filter_naming_a_type_the_caller_may_not_read_is_forbidden(): void
    {
        $this->assertForbidden('ada', '/crates?filter[subject][type]=safes');
        $this->assertForbidden('ada', '/crates?filter[subject][type]=vaults');
        $this->assertForbidden('ada', '/crates?filter[marked][type]=safes&filter[marked][id]=1');
        $this->assertForbidden('ada', '/crates/-actions/tally?filter[subject][type]=vaults', [], 'POST');
        self::assertSame(['3'], $this->crateIds('admin', 'filter[subject][type]=vaults'));
        self::assertSame(['1'], $this->crateIds('admin', 'filter[marked][type]=safes'));
        self::assertSame([], $this->crateIds('admin', 'filter[subject][type]=safes'));
    }

    #[Test]
    public function a_morph_type_the_relation_does_not_declare_stays_a_bad_request(): void
    {
        $response = $this->send('ada', '/crates?filter[subject][type]=ledgers');
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * @param array<string, string> $server
     */
    private function assertForbidden(string $user, string $path, array $server = [], string $method = 'GET'): void
    {
        $response = $this->send($user, $path, $server, $method);
        self::assertSame(403, $response->getStatusCode(), $path . ': ' . $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertIsArray($errors[0] ?? null);
        self::assertSame('ACCESS_DENIED', $errors[0]['code'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function crateIds(string $user, string $query): array
    {
        return $this->ids($this->document($user, '/crates' . ($query === '' ? '' : '?' . $query))['data'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function tally(string $user, string $query): array
    {
        $response = $this->send($user, '/crates/-actions/tally?' . $query, [], 'POST');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $meta = $this->decode($response)['meta'] ?? null;
        self::assertIsArray($meta);
        $ids = $meta['ids'] ?? null;
        self::assertIsArray($ids);

        return $this->ids(\array_map(static fn(mixed $id): array => ['id' => $id], $ids));
    }

    /**
     * @param array<string, string> $server
     *
     * @return array<string, mixed>
     */
    private function document(string $user, string $path, array $server = []): array
    {
        $response = $this->send($user, $path, $server);
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param array<string, string> $server
     */
    private function send(string $user, string $path, array $server = [], string $method = 'GET'): Response
    {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $user;

        return $this->handle($path, $method, null, $server);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function relationshipData(array $document, string $name): mixed
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $relationship = $relationships[$name] ?? null;
        self::assertIsArray($relationship);

        return $relationship['data'] ?? null;
    }

    /**
     * The ids of a list of resources or identifiers, sorted.
     *
     * @return list<string>
     */
    private function ids(mixed $resources): array
    {
        self::assertIsArray($resources);
        $ids = [];
        foreach ($resources as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            $ids[] = \is_scalar($id) ? (string) $id : '';
        }
        \sort($ids);

        return $ids;
    }
}
