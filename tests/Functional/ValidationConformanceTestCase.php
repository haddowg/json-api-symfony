<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Symfony Validator bridge end to end: a resource's declared constraints
 * (`title` required + length, `category` an enum — see
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseArticleResource})
 * are translated to Symfony rules, run against the create/update document, and
 * rendered as `422`s with `source.pointer`s. The same assertions run against the
 * in-memory and Doctrine kernels, so the bridge's behaviour is provider-agnostic.
 *
 * Validation is wired automatically because `symfony/validator` is installed; the
 * handler's optional validator resolves to the bundle's `ResourceValidator`.
 */
abstract class ValidationConformanceTestCase extends JsonApiFunctionalTestCase
{
    use ClockSensitiveTrait;

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithoutARequiredAttributeReturns422WithAPointer(): void
    {
        $response = $this->handle('/articles', 'POST', [
            'data' => ['type' => 'articles', 'attributes' => ['body' => 'No title.', 'category' => 'news']],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['/data/attributes/title'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithATooShortValueReturns422AtThatPointer(): void
    {
        $response = $this->handle('/articles', 'POST', [
            'data' => ['type' => 'articles', 'attributes' => ['title' => 'ab', 'category' => 'news']],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['/data/attributes/title'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithAValueOutsideTheEnumReturns422AtThatPointer(): void
    {
        $response = $this->handle('/articles', 'POST', [
            'data' => ['type' => 'articles', 'attributes' => ['title' => 'A fine title', 'category' => 'sports']],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['/data/attributes/category'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function multipleViolationsAllRenderUnderASingle422(): void
    {
        // Missing required title AND an out-of-enum category: a uniform bag of two
        // 422s must render as 422 (the core status-fidelity fix), not a rounded 400.
        $response = $this->handle('/articles', 'POST', [
            'data' => ['type' => 'articles', 'attributes' => ['category' => 'sports']],
        ]);

        self::assertSame(422, $response->getStatusCode());

        $pointers = $this->pointers($response);
        self::assertContains('/data/attributes/title', $pointers);
        self::assertContains('/data/attributes/category', $pointers);
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingWithAnInvalidValueReturns422(): void
    {
        $response = $this->handle('/articles/1', 'PATCH', [
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'ab']],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['/data/attributes/title'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingMayOmitARequiredAttribute(): void
    {
        // On update a required attribute may be absent (a partial update); only an
        // explicitly invalid supplied value fails.
        $response = $this->handle('/articles/1', 'PATCH', [
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['category' => 'opinion']],
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    #[Group('spec:crud')]
    public function aValidDocumentPassesValidation(): void
    {
        $response = $this->handle('/articles', 'POST', [
            'data' => ['type' => 'articles', 'attributes' => ['title' => 'Perfectly valid', 'category' => 'guide']],
        ]);

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithADateThatViolatesAClosureBoundReturns422AtThatPointer(): void
    {
        // The clock is frozen so the resource's `before(now)` bound is deterministic:
        // a publish date a day in the future must fail "not in the future".
        self::mockTime(new \DateTimeImmutable('2026-06-08T12:00:00+00:00'));

        $response = $this->handle('/articles', 'POST', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title',
                'category' => 'news',
                'publishedAt' => '2026-06-09T12:00:00+00:00',
            ]],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['/data/attributes/publishedAt'], $this->pointers($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithADateWithinAClosureBoundPasses(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-06-08T12:00:00+00:00'));

        $response = $this->handle('/articles', 'POST', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title',
                'category' => 'guide',
                'publishedAt' => '2026-06-01T12:00:00+00:00',
            ]],
        ]);

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    #[Group('spec:crud')]
    public function aCrossFieldRuleComparesAgainstTheSiblingValue(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-06-08T12:00:00+00:00'));

        // expiresAt must be after publishedAt: an earlier expiry fails at its pointer...
        $expiresBeforePublished = $this->handle('/articles', 'POST', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title', 'category' => 'news',
                'publishedAt' => '2026-06-06T12:00:00+00:00',
                'expiresAt' => '2026-06-05T12:00:00+00:00',
            ]],
        ]);

        self::assertSame(422, $expiresBeforePublished->getStatusCode());
        self::assertSame(['/data/attributes/expiresAt'], $this->pointers($expiresBeforePublished));

        // ...while a later expiry is accepted.
        $expiresAfterPublished = $this->handle('/articles', 'POST', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title', 'category' => 'guide',
                'publishedAt' => '2026-06-06T12:00:00+00:00',
                'expiresAt' => '2026-06-07T12:00:00+00:00',
            ]],
        ]);

        self::assertSame(201, $expiresAfterPublished->getStatusCode());
    }

    #[Test]
    #[Group('spec:crud')]
    public function aConditionalConstraintIsEnforcedOnlyWhenItsConditionHolds(): void
    {
        // couponCode is length-checked only when it looks like a promo code, so a
        // short "PROMO-X" fails the when()-declared rule at its pointer...
        $promoTooShort = $this->handle('/articles', 'POST', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title', 'category' => 'news', 'couponCode' => 'PROMO-X',
            ]],
        ]);

        self::assertSame(422, $promoTooShort->getStatusCode());
        self::assertSame(['/data/attributes/couponCode'], $this->pointers($promoTooShort));

        // ...while an equally short non-promo code skips the rule and is accepted.
        $nonPromoShort = $this->handle('/articles', 'POST', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title', 'category' => 'guide', 'couponCode' => 'FREE',
            ]],
        ]);

        self::assertSame(201, $nonPromoShort->getStatusCode());
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
