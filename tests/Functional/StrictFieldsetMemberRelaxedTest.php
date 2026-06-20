<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\StrictFieldsetFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\StrictFieldsetRelaxedTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The relax toggle for strict `fields[type]` sparse-fieldset member validation: with
 * `json_api.strict_query_parameters` set to `false`, an unknown `fields[type]` member
 * is silently ignored (the pre-strict behaviour) instead of rejected with a `400`.
 * The default-on path is the dual-provider
 * {@see StrictFieldsetMemberConformanceTestCase}; this single in-memory witness proves
 * the existing strict gate also stands down the broadened member check.
 */
final class StrictFieldsetMemberRelaxedTest extends JsonApiFunctionalTestCase
{
    protected function afterBoot(): void
    {
        StrictFieldsetFactory::reset();
    }

    protected static function getKernelClass(): string
    {
        return StrictFieldsetRelaxedTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching')]
    public function anUnknownFieldsetMemberIsIgnoredWhenStrictModeIsOff(): void
    {
        // With strict mode off the broadened member check stands down, so the unknown
        // `bogus` member is silently dropped and the request succeeds — the pre-strict
        // behaviour the toggle restores. `title` still narrows the sparse set.
        $response = $this->handle('/leaflets?fields[leaflets]=title,bogus');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $document = $this->decode($response);
        self::assertIsArray($document['data'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function anUnknownIncludedTypeMemberIsIgnoredWhenStrictModeIsOff(): void
    {
        // The relaxed gate also stands the related-type member check down: an unknown
        // `fields[stickers]` member on an included resource is dropped rather than 400.
        $response = $this->handle('/leaflets?include=sticker&fields[stickers]=label,nope');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }
}
