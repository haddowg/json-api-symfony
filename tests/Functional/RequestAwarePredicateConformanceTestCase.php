<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The request-aware-predicates acceptance suite (core ADRs 0079/0080, bundle ADR
 * 0084): every field-visibility / writability / relationship-authz predicate and
 * the widened validation `when()`, each declared on the `badges` fixture as a
 * closure keyed off the inbound `X-Role` header, executed end-to-end over HTTP. The
 * caller is varied purely by that header (`extraServer: ['HTTP_X_ROLE' => 'admin']`)
 * — no security plumbing, because {@see \haddowg\JsonApi\Request\JsonApiRequestInterface}
 * is a PSR-7 request, so the predicate is provider-agnostic.
 *
 * Abstract over the kernel so the **same assertions** run against the in-memory
 * provider ({@see InMemoryRequestAwarePredicateTest}) and the Doctrine provider
 * ({@see DoctrineRequestAwarePredicateTest}); a failure on one localizes the bug to
 * that provider's execution of the core resolvers.
 *
 * The fixture seed (identical on both providers): badge 1 ("First", rank "bronze",
 * secret "topsecret", clearance "secret") holding medal 1; medals 1-3
 * ("Gold"/"Silver"/"Bronze").
 */
abstract class RequestAwarePredicateConformanceTestCase extends JsonApiFunctionalTestCase
{
    /**
     * The `X-Role: admin` header is the only thing that varies the caller.
     */
    private const ADMIN = ['HTTP_X_ROLE' => 'admin'];

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aHiddenAttributeIsPresentForAdminAndAbsentOtherwise(): void
    {
        // hidden(fn => non-admin): the `secret` attribute renders for an admin read.
        $admin = $this->attributesOf($this->fetchDocument('/badges/1', self::ADMIN));
        self::assertArrayHasKey('secret', $admin);
        self::assertSame('topsecret', $admin['secret']);

        // For a guest the predicate hides it — even though it is a declared,
        // non-sparse attribute.
        $guest = $this->attributesOf($this->fetchDocument('/badges/1'));
        self::assertArrayNotHasKey('secret', $guest);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aHiddenAttributeIsAbsentForAGuestInEverySerializationContext(): void
    {
        // The hidden predicate is the transformer's per-object authority, so it must
        // fire wherever a badge is serialized — not just as the single primary above.

        // 1. As a member of a PRIMARY COLLECTION (`GET /badges`).
        $collection = $this->fetchDocument('/badges');
        $members = $collection['data'] ?? null;
        self::assertIsArray($members);
        self::assertNotSame([], $members);
        foreach ($members as $member) {
            self::assertIsArray($member);
            $attributes = $member['attributes'] ?? [];
            self::assertIsArray($attributes);
            self::assertArrayNotHasKey('secret', $attributes);
        }

        // 2. As an INCLUDED resource (`GET /medals/1?include=badges` — the badge rides
        //    the compound document's `included` member off the inverse relation).
        $included = $this->includedOfType($this->fetchDocument('/medals/1?include=badges'), 'badges');
        self::assertNotSame([], $included, 'expected the badge to be included');
        foreach ($included as $badge) {
            $attributes = $badge['attributes'] ?? [];
            self::assertIsArray($attributes);
            self::assertArrayNotHasKey('secret', $attributes);
        }

        // 3. As the PRIMARY of a RELATED read (`GET /medals/1/badges`).
        $related = $this->fetchDocument('/medals/1/badges');
        $relatedMembers = $related['data'] ?? null;
        self::assertIsArray($relatedMembers);
        self::assertNotSame([], $relatedMembers, 'expected the related badge collection to be non-empty');
        foreach ($relatedMembers as $member) {
            self::assertIsArray($member);
            $attributes = $member['attributes'] ?? [];
            self::assertIsArray($attributes);
            self::assertArrayNotHasKey('secret', $attributes);
        }

        // The same three contexts DO render `secret` for an admin (the predicate is the
        // only thing varying), confirming the absence above is the predicate, not the
        // member simply never being there.
        $adminRelated = $this->fetchDocument('/medals/1/badges', self::ADMIN);
        $adminMembers = $adminRelated['data'] ?? null;
        self::assertIsArray($adminMembers);
        self::assertNotSame([], $adminMembers);
        foreach ($adminMembers as $member) {
            self::assertIsArray($member);
            $attributes = $member['attributes'] ?? [];
            self::assertIsArray($attributes);
            self::assertArrayHasKey('secret', $attributes);
        }
    }

    #[Test]
    #[Group('spec:fetching-sparse-fieldsets')]
    public function aHiddenAttributeCannotBeResurrectedByASparseFieldset(): void
    {
        // Naming the hidden field explicitly does not un-hide it for a guest.
        $guest = $this->attributesOf($this->fetchDocument('/badges/1?fields[badges]=secret'));
        self::assertArrayNotHasKey('secret', $guest);
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function aWriteOnlyAttributeIsAcceptedButNeverRendered(): void
    {
        // writeOnly(fn => true): the secret is accepted on a write…
        $response = $this->handle('/badges', 'POST', [
            'data' => [
                'type' => 'badges',
                'attributes' => ['name' => 'Created', 'writeOnlySecret' => 'shhh'],
            ],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        // …but rendered on no read: not in the create response body…
        self::assertArrayNotHasKey('writeOnlySecret', $this->attributesOf($this->decode($response)));

        // …and not on a follow-up fetch of the created resource (even for an admin).
        $id = $this->idOf($this->decode($response));
        self::assertArrayNotHasKey(
            'writeOnlySecret',
            $this->attributesOf($this->fetchDocument('/badges/' . $id, self::ADMIN)),
        );
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function aReadOnlyOnUpdateAttributeIsSilentlyIgnoredForNonAdmin(): void
    {
        // readOnlyOnUpdate(fn => non-admin): a guest PATCH of `rank` is dropped — the
        // field is read-only for it, so it is never hydrated (no spurious 422 either).
        $response = $this->handle('/badges/1', 'PATCH', [
            'data' => ['type' => 'badges', 'id' => '1', 'attributes' => ['rank' => 'platinum']],
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('bronze', $this->attributesOf($this->decode($response))['rank'] ?? null);

        // The stored value is unchanged.
        self::assertSame('bronze', $this->attributesOf($this->fetchDocument('/badges/1'))['rank'] ?? null);
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function aReadOnlyOnUpdateAttributeIsWritableForAdmin(): void
    {
        // The same PATCH from an admin applies — the field is writable for it.
        $response = $this->handle('/badges/1', 'PATCH', [
            'data' => ['type' => 'badges', 'id' => '1', 'attributes' => ['rank' => 'platinum']],
        ], self::ADMIN);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('platinum', $this->attributesOf($this->decode($response))['rank'] ?? null);

        self::assertSame(
            'platinum',
            $this->attributesOf($this->fetchDocument('/badges/1', self::ADMIN))['rank'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aGatedRelationshipReplacementIs403ForNonAdminAnd200ForAdmin(): void
    {
        $body = ['data' => [['type' => 'medals', 'id' => '2']]];

        // cannotReplace(fn => non-admin): a guest PATCH to the relationship endpoint
        // is FullReplacementProhibited (403)…
        $guest = $this->handle('/badges/1/relationships/medals', 'PATCH', $body);
        self::assertSame(403, $guest->getStatusCode(), (string) $guest->getContent());

        // …while an admin's replacement succeeds and persists.
        $admin = $this->handle('/badges/1/relationships/medals', 'PATCH', $body, self::ADMIN);
        self::assertSame(200, $admin->getStatusCode(), (string) $admin->getContent());
        self::assertSame(
            [['type' => 'medals', 'id' => '2']],
            $this->identifiersOf('/badges/1/relationships/medals'),
        );
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aGatedRelationshipAdditionIs403ForNonAdminAnd200ForAdmin(): void
    {
        $body = ['data' => [['type' => 'medals', 'id' => '2']]];

        // cannotAdd(fn => non-admin): a guest POST is AdditionProhibited (403).
        $guest = $this->handle('/badges/1/relationships/medals', 'POST', $body);
        self::assertSame(403, $guest->getStatusCode(), (string) $guest->getContent());

        // An admin's add succeeds and the set now holds medals 1 and 2.
        $admin = $this->handle('/badges/1/relationships/medals', 'POST', $body, self::ADMIN);
        self::assertSame(200, $admin->getStatusCode(), (string) $admin->getContent());
        self::assertSame(
            [['type' => 'medals', 'id' => '1'], ['type' => 'medals', 'id' => '2']],
            $this->identifiersOf('/badges/1/relationships/medals'),
        );
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aGatedRelationshipRemovalIs403ForNonAdminAnd200ForAdmin(): void
    {
        $body = ['data' => [['type' => 'medals', 'id' => '1']]];

        // cannotRemove(fn => non-admin): a guest DELETE is RemovalProhibited (403).
        $guest = $this->handle('/badges/1/relationships/medals', 'DELETE', $body);
        self::assertSame(403, $guest->getStatusCode(), (string) $guest->getContent());

        // An admin's remove succeeds and the set is now empty.
        $admin = $this->handle('/badges/1/relationships/medals', 'DELETE', $body, self::ADMIN);
        self::assertSame(200, $admin->getStatusCode(), (string) $admin->getContent());
        self::assertSame([], $this->identifiersOf('/badges/1/relationships/medals'));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function anEmbeddedGatedRelationshipReplacementInAWholeResourcePatchIs403ForNonAdmin(): void
    {
        // The replacement gate must also fire when the gated relation is embedded in a
        // whole-resource PATCH body (an embedded write is a FULL replacement), not only
        // at the dedicated /relationships endpoint — otherwise the gate is bypassable.
        $body = [
            'data' => [
                'type' => 'badges',
                'id' => '1',
                'relationships' => [
                    'medals' => ['data' => [['type' => 'medals', 'id' => '2']]],
                ],
            ],
        ];

        // cannotReplace(fn => non-admin): a guest PATCH embedding the relation is
        // FullReplacementProhibited (403), and the membership is unchanged…
        $guest = $this->handle('/badges/1', 'PATCH', $body);
        self::assertSame(403, $guest->getStatusCode(), (string) $guest->getContent());
        self::assertSame(
            [['type' => 'medals', 'id' => '1']],
            $this->identifiersOf('/badges/1/relationships/medals'),
        );

        // …while an admin's embedded replacement succeeds and persists.
        $admin = $this->handle('/badges/1', 'PATCH', $body, self::ADMIN);
        self::assertSame(200, $admin->getStatusCode(), (string) $admin->getContent());
        self::assertSame(
            [['type' => 'medals', 'id' => '2']],
            $this->identifiersOf('/badges/1/relationships/medals'),
        );
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function anEmbeddedGatedRelationshipInAWholeResourcePostIsAllowedForNonAdmin(): void
    {
        // The create exception: a POST sets the relationship's INITIAL state (there is
        // nothing to replace), so the replacement gate does NOT apply — otherwise a
        // cannotReplace relation could never be set, as it has no relationship endpoint.
        $response = $this->handle('/badges', 'POST', [
            'data' => [
                'type' => 'badges',
                'attributes' => ['name' => 'WithMedals'],
                'relationships' => [
                    'medals' => ['data' => [['type' => 'medals', 'id' => '2']]],
                ],
            ],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        // The relation was set on the created badge despite the caller being a guest.
        $id = $this->idOf($this->decode($response));
        self::assertSame(
            [['type' => 'medals', 'id' => '2']],
            $this->identifiersOf('/badges/' . $id . '/relationships/medals'),
        );
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aGatedIncludeIs400ForNonAdminAndExpandsForAdmin(): void
    {
        // cannotBeIncluded(fn => non-admin): naming the gated relation in `?include`
        // is rejected (400) for a guest — core's InclusionNotAllowed.
        $guest = $this->handle('/badges/1?include=secretMedals');
        self::assertSame(400, $guest->getStatusCode(), (string) $guest->getContent());

        // For an admin it is includable, so the document carries the compound member.
        $admin = $this->fetchDocument('/badges/1?include=secretMedals', self::ADMIN);
        $included = $admin['included'] ?? null;
        self::assertIsArray($included);
        $types = \array_map(static fn(mixed $r): mixed => \is_array($r) ? ($r['type'] ?? null) : null, $included);
        self::assertContains('medals', $types);
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function aConditionallyRequiredAttributeIs422ForAdminOmittingItButAcceptedOtherwise(): void
    {
        // when(fn($v, $req) => admin, required()): the widened condition sees the
        // request, so an admin omitting `clearance` is a 422…
        $admin = $this->handle('/badges', 'POST', [
            'data' => ['type' => 'badges', 'attributes' => ['name' => 'NeedsClearance']],
        ], self::ADMIN);
        self::assertSame(422, $admin->getStatusCode(), (string) $admin->getContent());
        self::assertContains(
            '/data/attributes/clearance',
            $this->errorPointers($admin),
            (string) $admin->getContent(),
        );

        // …while a guest omitting it is accepted (the condition is false for it).
        $guest = $this->handle('/badges', 'POST', [
            'data' => ['type' => 'badges', 'attributes' => ['name' => 'NoClearance']],
        ]);
        self::assertSame(201, $guest->getStatusCode(), (string) $guest->getContent());
    }

    /**
     * Fetches `$path` and returns the decoded document, asserting a 200 JSON:API
     * response.
     *
     * @param array<string, string> $server extra `$_SERVER` entries (e.g. the admin header)
     *
     * @return array<string, mixed>
     */
    private function fetchDocument(string $path, array $server = []): array
    {
        $response = $this->handle($path, 'GET', null, $server);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
     * The `attributes` of a single-resource document's primary data.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function attributesOf(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? [];
        self::assertIsArray($attributes);

        return $attributes;
    }

    /**
     * The id of a single-resource document's primary data.
     *
     * @param array<string, mixed> $document
     */
    private function idOf(array $document): string
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * The `included` resources of `$document` whose `type` is `$type`.
     *
     * @param array<string, mixed> $document
     *
     * @return list<array<string, mixed>>
     */
    private function includedOfType(array $document, string $type): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        $matched = [];
        foreach ($included as $resource) {
            if (\is_array($resource) && ($resource['type'] ?? null) === $type) {
                /** @var array<string, mixed> $resource */
                $matched[] = $resource;
            }
        }

        return $matched;
    }

    /**
     * The to-many linkage identifiers of a relationship document fetched from `$path`.
     *
     * @return list<array{type: string, id: string}>
     */
    private function identifiersOf(string $path): array
    {
        $document = $this->fetchDocument($path);
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $identifiers = [];
        foreach ($data as $member) {
            self::assertIsArray($member);
            $type = $member['type'] ?? null;
            $id = $member['id'] ?? null;
            self::assertIsString($type);
            self::assertIsString($id);
            $identifiers[] = ['type' => $type, 'id' => $id];
        }

        return $identifiers;
    }

    /**
     * The `source.pointer`s of an error document.
     *
     * @return list<string>
     */
    private function errorPointers(Response $response): array
    {
        $document = $this->decode($response);
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);

        $pointers = [];
        foreach ($errors as $error) {
            self::assertIsArray($error);
            $source = $error['source'] ?? null;
            if (\is_array($source) && \is_string($source['pointer'] ?? null)) {
                $pointers[] = $source['pointer'];
            }
        }

        return $pointers;
    }
}
