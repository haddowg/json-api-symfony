<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Symfony Validator bridge end to end (backs `validation.md`): the resources'
 * declared core constraints are translated to Symfony rules, run against the
 * create/update document before hydration, and rendered as `422`s with
 * `source.pointer`s — plus the post-hydration entity-level `UniqueEntity` rule on
 * `users.email`.
 *
 * `UniqueEntity` queries the user repository through **`symfony/doctrine-bridge`**
 * (a runtime requirement the example app depends on); it resolves on the `admin`
 * server, where `UserResource` lives. The closure date bound on `albums.releasedAt`
 * (`before(now)`) is a closure resolved at validation time, so a far-future date is
 * rejected and a past date accepted.
 */
#[Group('spec:crud')]
final class ValidationTest extends MusicCatalogKernelTestCase
{
    // --- presence / format / range, the document-first pass ------------------

    #[Test]
    #[Group('spec:creating-resources')]
    public function creatingWithoutARequiredAttributeReturns422WithAPointer(): void
    {
        // `title` is required on create; omitting it fails with a clean pointer.
        $response = $this->handle('/tracks', 'POST', [
            'data' => ['type' => 'tracks', 'attributes' => [
                'trackNumber' => 1, 'durationSeconds' => 10, 'genres' => ['rock'],
            ]],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['/data/attributes/title'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function creatingWithAValueBelowAMinimumReturns422AtThatPointer(): void
    {
        // `trackNumber` carries min(1): zero fails GreaterThanOrEqual.
        $response = $this->handle('/tracks', 'POST', [
            'data' => ['type' => 'tracks', 'attributes' => [
                'title' => 'A fine title', 'trackNumber' => 0, 'durationSeconds' => 10, 'genres' => ['rock'],
            ]],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['/data/attributes/trackNumber'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function creatingWithAnEmptyArrayListReturns422AtThatPointer(): void
    {
        // `genres` is an ArrayList with minItems(1): an empty list fails Count.
        $response = $this->handle('/tracks', 'POST', [
            'data' => ['type' => 'tracks', 'attributes' => [
                'title' => 'A fine title', 'trackNumber' => 1, 'durationSeconds' => 10, 'genres' => [],
            ]],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['/data/attributes/genres'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function updatingWithAnInvalidEmailReturns422AtThatPointer(): void
    {
        // `email` carries a (strict) EmailFormat — a malformed value fails on update.
        $response = $this->handle('/admin/users/1', 'PATCH', [
            'data' => ['type' => 'users', 'id' => '1', 'attributes' => ['email' => 'not-an-email']],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['/data/attributes/email'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function updatingARequiredAttributeWithABlankValueReturns422(): void
    {
        // A PATCH may omit `displayName`, but a *supplied* required value must be
        // non-empty: an empty string fails NotBlank.
        $response = $this->handle('/admin/users/1', 'PATCH', [
            'data' => ['type' => 'users', 'id' => '1', 'attributes' => ['displayName' => '']],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['/data/attributes/displayName'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function updatingMayOmitARequiredAttribute(): void
    {
        // On update a required attribute may be absent (a partial update); track 1
        // keeps its seeded title.
        $response = $this->handle('/tracks/1', 'PATCH', [
            'data' => ['type' => 'tracks', 'id' => '1', 'attributes' => ['durationSeconds' => 290]],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    // --- cross-field comparison (CompareField) -------------------------------

    #[Test]
    #[Group('spec:updating-resources')]
    public function aDirectionalCompareFieldComparesAgainstTheSiblingValue(): void
    {
        // Album `availableUntil` must be greater than `availableFrom`. An earlier
        // "until" fails at its own pointer...
        $invalid = $this->handle('/albums/1', 'PATCH', [
            'data' => ['type' => 'albums', 'id' => '1', 'attributes' => [
                'availableFrom' => '2030-01-01', 'availableUntil' => '2020-01-01',
            ]],
        ]);

        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(['/data/attributes/availableUntil'], $this->pointers($invalid));

        // ...while a later "until" is accepted.
        $valid = $this->handle('/albums/1', 'PATCH', [
            'data' => ['type' => 'albums', 'id' => '1', 'attributes' => [
                'availableFrom' => '2020-01-01', 'availableUntil' => '2030-01-01',
            ]],
        ]);

        self::assertSame(200, $valid->getStatusCode(), (string) $valid->getContent());
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function aPartialUpdateComparesAgainstAStoredSiblingNotInTheBody(): void
    {
        // Merge-before-validate (bundle ADR 0049/0050): album 1 is stored with
        // `availableFrom` 1997-05-21. A partial PATCH that sends ONLY `availableUntil`
        // — and never re-sends the stored `availableFrom` — must still evaluate the
        // directional `availableUntil > availableFrom` rule against the MERGED
        // resource (the stored sibling folded under the body). An "until" AFTER the
        // stored "from" passes...
        $valid = $this->handle('/albums/1', 'PATCH', [
            'data' => ['type' => 'albums', 'id' => '1', 'attributes' => [
                'availableUntil' => '2040-01-01',
            ]],
        ]);

        self::assertSame(200, $valid->getStatusCode(), (string) $valid->getContent());

        // ...while an "until" BEFORE the stored "from" violates the merged result —
        // a 422 at the owner pointer, even though `availableFrom` is absent from the
        // body. Were the stored sibling not merged in, the comparison would have no
        // sibling to compare against and wrongly pass.
        $invalid = $this->handle('/albums/1', 'PATCH', [
            'data' => ['type' => 'albums', 'id' => '1', 'attributes' => [
                'availableUntil' => '1990-01-01',
            ]],
        ]);

        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(['/data/attributes/availableUntil'], $this->pointers($invalid));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function anEqualityCompareFieldComparesTwoSiblingValues(): void
    {
        // `passwordConfirm` must equal `password` (non-directional EqualTo): a
        // mismatch fails at the confirm pointer.
        $response = $this->handle('/admin/users/1', 'PATCH', [
            'data' => ['type' => 'users', 'id' => '1', 'attributes' => [
                'password' => 'longpassword1', 'passwordConfirm' => 'different',
            ]],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['/data/attributes/passwordConfirm'], $this->pointers($response));
    }

    // --- closure date bound (Callback) ---------------------------------------

    #[Test]
    #[Group('spec:updating-resources')]
    public function aClosureDateBoundIsResolvedAtValidationTime(): void
    {
        // `releasedAt` carries before(now): the bound is a closure resolved at
        // validation time, so a far-future publish date fails "not in the future"...
        $future = $this->handle('/albums/1', 'PATCH', [
            'data' => ['type' => 'albums', 'id' => '1', 'attributes' => [
                'releasedAt' => '2999-01-01T00:00:00+00:00',
            ]],
        ]);

        self::assertSame(422, $future->getStatusCode());
        self::assertSame(['/data/attributes/releasedAt'], $this->pointers($future));

        // ...while a clearly past date is accepted.
        $past = $this->handle('/albums/1', 'PATCH', [
            'data' => ['type' => 'albums', 'id' => '1', 'attributes' => [
                'releasedAt' => '2000-01-01T00:00:00+00:00',
            ]],
        ]);

        self::assertSame(200, $past->getStatusCode(), (string) $past->getContent());
    }

    // --- multiple violations under one 422 -----------------------------------

    #[Test]
    #[Group('spec:creating-resources')]
    public function multipleViolationsAllRenderUnderASingle422(): void
    {
        // A missing required title AND a below-minimum trackNumber: a uniform bag of
        // two 422s must render as 422 (status fidelity), not a rounded 400.
        $response = $this->handle('/tracks', 'POST', [
            'data' => ['type' => 'tracks', 'attributes' => [
                'trackNumber' => 0, 'durationSeconds' => 10, 'genres' => ['rock'],
            ]],
        ]);

        self::assertSame(422, $response->getStatusCode());

        $pointers = $this->pointers($response);
        self::assertContains('/data/attributes/title', $pointers);
        self::assertContains('/data/attributes/trackNumber', $pointers);
    }

    // --- entity-level UniqueEntity (post-hydration, doctrine-bridge) ----------

    #[Test]
    #[Group('spec:creating-resources')]
    public function creatingWithADuplicateUniqueEmailReturns422AtThatPointer(): void
    {
        // `ada@example.com` is seeded; the UniqueEntity rule queries the repository
        // post-hydration and rejects the duplicate before commit.
        $response = $this->handle('/admin/users', 'POST', [
            'data' => ['type' => 'users', 'attributes' => [
                'email' => 'ada@example.com', 'displayName' => 'Ada Two',
            ]],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['/data/attributes/email'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function updatingAUserWithItsOwnEmailPassesUniqueEntity(): void
    {
        // UniqueEntity excludes the current record on update, so re-supplying the
        // user's own email is not a duplicate.
        $response = $this->handle('/admin/users/1', 'PATCH', [
            'data' => ['type' => 'users', 'id' => '1', 'attributes' => ['email' => 'ada@example.com']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * The `source.pointer` of every error in the response document.
     *
     * @return list<string>
     */
    private function pointers(Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);

        $pointers = [];
        foreach ($errors as $error) {
            self::assertIsArray($error);
            self::assertSame('422', $error['status'] ?? null);

            $source = $error['source'] ?? null;
            self::assertIsArray($source);

            $pointer = $source['pointer'] ?? null;
            self::assertIsString($pointer);
            $pointers[] = $pointer;
        }

        return $pointers;
    }
}
