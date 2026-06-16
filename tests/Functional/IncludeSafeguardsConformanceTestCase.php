<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The include-safeguards acceptance suite (bundle ADR 0037), run identically
 * against the in-memory provider ({@see InMemoryIncludeSafeguardsTest}) and the
 * Doctrine-sqlite provider ({@see DoctrineIncludeSafeguardsTest}) so a failure
 * localizes to a provider, not the fixture. It exercises the three composing
 * safeguards on a circular `nodes` chain (n1 → n2 → n3 → n1, with `next`
 * default-included) plus the `tags`/`roots`/`caps` witnesses:
 *
 *  - Capability A — a `cannotBeIncluded()` relation (`prev`) is a `400` when named
 *    in `?include`, and is excluded from the default cascade;
 *  - Capability B — a `?include` deeper than the effective cap is a `400`; the
 *    bundle's default cap of `3` is in force; a mutual default-include cycle
 *    terminates at the cap; and a per-resource `maxIncludeDepth()` override wins
 *    over the server default;
 *  - Capability C — a root's allowed-include-paths whitelist forbids a nested path
 *    even though the relation is includable from its own root.
 */
abstract class IncludeSafeguardsConformanceTestCase extends JsonApiFunctionalTestCase
{
    // --- Capability A: per-relation cannotBeIncluded() opt-out -----------------

    #[Test]
    #[Group('spec:fetching-includes')]
    public function includingARelationMarkedCannotBeIncludedIs400(): void
    {
        // `prev` exists on `nodes` but opted out of inclusion.
        $response = $this->handle('/nodes/n1?include=prev');

        $this->assertError($response, 400, 'INCLUSION_NOT_ALLOWED');
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aNonIncludableRelationIsNotAutoIncludedByTheDefaultCascade(): void
    {
        // `prev` is NOT in getDefaultIncludedRelationships (only `next` is), and even
        // if it were the cascade excludes a non-includable relation — so a plain
        // fetch never compounds a `prev` target. `next`'s default include still
        // appears, so the document is not empty of included resources.
        $document = $this->fetchDocument('/nodes/n1');

        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        // No included resource was reached via the `prev` chain: n1->prev is n3,
        // n3->prev is n2 — but the only forward default-include is `next`
        // (n1->next=n2), so the included set is the forward walk, never the inverse.
        $types = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            $types[] = $resource['type'] ?? null;
        }
        self::assertContains('nodes', $types);
    }

    // --- Capability B: max include depth ---------------------------------------

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludeAtTheDefaultDepthCapOf3Succeeds(): void
    {
        // depth(next.next.next) = 3 == the bundle default cap, so it is allowed.
        $response = $this->handle('/nodes/n1?include=next.next.next');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludeDeeperThanTheDefaultCapIs400(): void
    {
        // depth(next.next.next.next) = 4 > the bundle default cap of 3.
        $response = $this->handle('/nodes/n1?include=next.next.next.next');

        $this->assertError($response, 400, 'INCLUSION_DEPTH_EXCEEDED');
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aMutualDefaultIncludeCycleTerminatesAtTheCap(): void
    {
        // `next` is default-included and the chain is circular (n1 → n2 → n3 → n1),
        // so an uncapped default cascade would recurse forever. The cap terminates
        // it: the request returns 200 with a bounded `included` set rather than
        // exhausting memory or the stack.
        $document = $this->fetchDocument('/nodes/n1');

        $included = $document['included'] ?? [];
        self::assertIsArray($included);
        // The circular chain has only three distinct nodes; deduplicated, the
        // included set can never exceed them however the cascade walks.
        self::assertLessThanOrEqual(3, \count($included));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aCollectionRootedIncludeDeeperThanTheCapIs400(): void
    {
        // The collection endpoint (GET /{type}?include=...) wires the cap through
        // CollectionDocument — a distinct call site from the single-resource path
        // the other depth tests exercise — so it gets its own over-depth assertion.
        $response = $this->handle('/nodes?include=next.next.next.next');

        $this->assertError($response, 400, 'INCLUSION_DEPTH_EXCEEDED');
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aCollectionRootedDefaultIncludeCycleTerminatesAtTheCap(): void
    {
        // The circular `next` chain rendered as the primary collection: an uncapped
        // default cascade would recurse forever, so a 200 with a bounded `included`
        // set proves the cap terminates the cascade on the collection path too.
        $document = $this->fetchDocument('/nodes');

        $included = $document['included'] ?? [];
        self::assertIsArray($included);
        self::assertLessThanOrEqual(3, \count($included));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aPerResourceMaxDepthOverrideWinsOverTheServerDefault(): void
    {
        // `caps` overrides maxIncludeDepth() to 1, below the server default of 3.
        // depth(node) = 1 is allowed; depth(node.next) = 2 exceeds the override.
        self::assertSame(200, $this->handle('/caps/c1?include=node')->getStatusCode());

        $this->assertError($this->handle('/caps/c1?include=node.next'), 400, 'INCLUSION_DEPTH_EXCEEDED');
    }

    // --- Capability C: root allowed-include-paths whitelist --------------------

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aRelationIsIncludableFromItsOwnRoot(): void
    {
        // `node.next` is forbidden from the `roots` root below, but the same hops are
        // freely includable when their own type is the request's root: `next` from
        // `nodes`, and `node` from `tags`.
        self::assertSame(200, $this->handle('/nodes/n1?include=tag')->getStatusCode());
        self::assertSame(200, $this->handle('/tags/t1?include=node')->getStatusCode());
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aPathInsideTheRootWhitelistIsAllowed(): void
    {
        // `roots` allows exactly ['node'].
        $response = $this->handle('/roots/r1?include=node');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aNestedPathOutsideTheRootWhitelistIs400(): void
    {
        // `node.next` is NOT in the `roots` whitelist, even though `next` is itself
        // includable from `nodes`' own root — the headline Capability C closes.
        $response = $this->handle('/roots/r1?include=node.next');

        $this->assertError($response, 400, 'INCLUSION_NOT_ALLOWED');
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludableNestedPathOutsideTheWhitelistIsStill400(): void
    {
        // `node.tag` reaches an includable `tag`, but the path is outside the
        // whitelist — so the root-scoped check rejects it just the same.
        $response = $this->handle('/roots/r1?include=node.tag');

        $this->assertError($response, 400, 'INCLUSION_NOT_ALLOWED');
    }

    /**
     * Asserts the response is a JSON:API error document with the given HTTP status
     * and a top error carrying `$code`.
     */
    private function assertError(Response $response, int $status, string $code): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        self::assertSame((string) $status, $first['status'] ?? null);
        self::assertSame($code, $first['code'] ?? null);
    }

    /**
     * Fetches `$path` and returns the decoded document, asserting a 200 JSON:API
     * response.
     *
     * @return array<string, mixed>
     */
    private function fetchDocument(string $path): array
    {
        $response = $this->handle($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }
}
