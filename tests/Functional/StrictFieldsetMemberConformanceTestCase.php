<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The dual-provider acceptance suite for strict `fields[type]` sparse-fieldset
 * **member** validation (Laravel-parity L#37; core
 * {@see \haddowg\JsonApi\Exception\FieldsetMemberUnrecognized} +
 * {@see \haddowg\JsonApi\Serializer\DeclaresFieldNamesInterface}, core ADR 0081),
 * run identically against the in-memory provider
 * ({@see InMemoryStrictFieldsetMemberTest}) and the Doctrine provider
 * ({@see DoctrineStrictFieldsetMemberTest}).
 *
 * With `json_api.strict_query_parameters` on (the default — both kernels here), a
 * `fields[type]` member that names no declared field of a known resource type is
 * rejected with a `400` (`source.parameter = fields`) naming the offending member,
 * rather than silently dropped — mirroring how an unknown `?include` path already
 * `400`s. The check broadens the existing strict gate (no new toggle); the relaxed
 * counterpart that proves the gate stands the member check down is
 * {@see StrictFieldsetMemberRelaxedTest}.
 *
 * The known-member set is the resource's FULL declared namespace, request-independent
 * (every field name, including hidden / non-sparse fields and `id`), so a member is
 * "unknown" only when it names no declared field at all — exercised by the tolerance
 * assertions below. The shared {@see App\Resource\BaseLeafletResource} /
 * {@see App\Resource\BaseStickerResource} declare that namespace.
 *
 * Scoping (out of scope for L#37, asserted tolerated): a `fields[type]` for an
 * unregistered/unresolvable TYPE is skipped — only unknown MEMBERS of KNOWN types are
 * rejected.
 */
abstract class StrictFieldsetMemberConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:fetching')]
    #[Group('spec:errors')]
    public function anUnknownFieldsetMemberOfThePrimaryTypeIsRejectedWith400(): void
    {
        // `title` is a declared field; `bogus` names none, so the strict member check
        // 400s on the offending member with source.parameter = fields.
        $response = $this->handle('/leaflets?fields[leaflets]=title,bogus');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $error = $this->firstError($this->decode($response));
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('FIELDSET_MEMBER_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'fields'], $error['source'] ?? null);
        self::assertStringContainsString('bogus', $this->detailOf($error));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aFieldsetNamingOnlyDeclaredMembersIsAccepted(): void
    {
        // `title` (a rendered attribute) and `id` (always a declared field) are both
        // declared, so a sparse fieldset naming only them passes the strict check.
        $response = $this->handle('/leaflets?fields[leaflets]=title,id');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function anEmptyFieldsetRendersNoAttributesAndIsAccepted(): void
    {
        // `?fields[leaflets]=` is a valid request meaning "render no fields of this
        // type" (only id/type). The empty value parses to the empty-string sentinel
        // member, which must NOT be flagged as unknown — the request succeeds and the
        // resource renders without attributes.
        $response = $this->handle('/leaflets?fields[leaflets]=');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $attributes = $this->firstResourceAttributes($this->decode($response));
        self::assertSame([], $attributes);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aFieldsetWithATrailingCommaIsAccepted(): void
    {
        // A trailing comma (`title,`) yields the empty-string sentinel alongside the
        // real `title` member; the sentinel must be tolerated, so the request succeeds.
        $response = $this->handle('/leaflets?fields[leaflets]=title,');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aFieldsetNamingAHiddenFieldIsAccepted(): void
    {
        // `secret` is unconditionally hidden (never rendered), yet a DECLARED field, so
        // naming it is tolerated — a hidden name and a bogus name must not be
        // distinguishable (no information leak). The response still renders no `secret`.
        $response = $this->handle('/leaflets?fields[leaflets]=secret');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $attributes = $this->firstResourceAttributes($this->decode($response));
        self::assertArrayNotHasKey('secret', $attributes);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aFieldsetNamingANonSparseFieldIsAccepted(): void
    {
        // `internalRef` is declared notSparseField() — a real declared field — so naming
        // it is tolerated even though it is exempt from sparse narrowing.
        $response = $this->handle('/leaflets?fields[leaflets]=internalRef');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aFieldsetNamingARelationshipFieldIsAccepted(): void
    {
        // `sticker` is a declared relationship field, so it is a valid fields[leaflets]
        // member — a relationship name is part of the same declared namespace.
        $response = $this->handle('/leaflets?fields[leaflets]=title,sticker');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:fetching')]
    #[Group('spec:errors')]
    public function anUnknownMemberOfAnUnregisteredTypeIsTolerated(): void
    {
        // A fields[type] for a TYPE the registry cannot resolve is out of scope for the
        // member check (only KNOWN types' members are validated), so it is skipped and
        // the request succeeds — the documented scoping boundary.
        $response = $this->handle('/leaflets?fields[unicorns]=whatever');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:fetching')]
    #[Group('spec:errors')]
    public function anUnknownMemberOfAnIncludedRelatedTypeIsRejectedWith400(): void
    {
        // The check covers EVERY named fields[type], not just the primary type: an
        // included `stickers` resource whose fields[stickers] names an unknown member
        // (`nope`) is rejected with the same 400, even though `label` is valid.
        $response = $this->handle('/leaflets?include=sticker&fields[stickers]=label,nope');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $error = $this->firstError($this->decode($response));
        self::assertSame('FIELDSET_MEMBER_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'fields'], $error['source'] ?? null);
        self::assertStringContainsString('nope', $this->detailOf($error));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aFieldsetForAnIncludedRelatedTypeNamingOnlyDeclaredMembersIsAccepted(): void
    {
        // The included `stickers` fieldset names only `label` — a declared field — so
        // the related-type member check passes and the document renders.
        $response = $this->handle('/leaflets?include=sticker&fields[stickers]=label');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function firstError(array $document): array
    {
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }

    /**
     * The error's `detail` string (asserted as a string so the offending-member
     * substring check stays typed under PHPStan).
     *
     * @param array<string, mixed> $error
     */
    private function detailOf(array $error): string
    {
        $detail = $error['detail'] ?? null;
        self::assertIsString($detail);

        return $detail;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function firstResourceAttributes(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        // `/leaflets` is a collection: the first resource object.
        $first = $data[0] ?? null;
        self::assertIsArray($first);

        $attributes = $first['attributes'] ?? [];
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }
}
