<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures\Seed;
use haddowg\JsonApiBundle\Tests\Functional\JsonApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The relax toggle for strict query-parameter validation (bundle ADR 0055) over the
 * example app: with `json_api.strict_query_parameters: false`, a *well-named*
 * unrecognized top-level query-parameter family is silently ignored (the pre-strict
 * behaviour) instead of rejected with a `400`. The default-on path is the shipped
 * {@see StrictQueryParametersTest}; this suite boots the {@see RelaxedQueryParametersKernel}
 * variant (the example app with the toggle off) to prove it flips the behaviour.
 *
 * Only the strict *superset* stands down: core's always-on spec baseline (the
 * all-a-z custom-param naming rule) still fires, so an all-lowercase `?bogus=1` or
 * the misspelled `?pag[number]=2` (base `pag`) remain a `400` even relaxed — the
 * relax toggle is witnessed against a well-named param (`?myParam=1`, and the
 * un-negotiated `relatedQuery` family), which the baseline lets through.
 *
 * It does not extend {@see MusicCatalogKernelTestCase} (which hard-names the shipped
 * kernel), so it repeats the schema-create + seed `afterBoot` here against the
 * variant kernel's in-memory database (modelled on {@see SchemaValidationTest}).
 */
final class RelaxedQueryParametersTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return RelaxedQueryParametersKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        Seed::into($entityManager);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aWellNamedUnsupportedCustomParameterIsIgnoredWhenStrictModeIsOff(): void
    {
        // `myParam` carries an uppercase letter, so it clears core's always-on baseline;
        // the strict superset is what would reject it. With strict off the superset
        // stands down, so the request succeeds and the unknown family is silently
        // dropped — exactly as before strict validation existed.
        $response = $this->handle('/albums?myParam=1');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $document = $this->decode($response);
        self::assertIsArray($document['data'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching')]
    #[Group('spec:profiles')]
    public function theRelatedQueryFamilyIsIgnoredWhenStrictModeIsOffAndTheProfileIsNotNegotiated(): void
    {
        // `relatedQuery` is a profile keyword (a well-named family, so it clears the
        // baseline) recognized only when the profile is negotiated. Without negotiation
        // and with strict off it is silently dropped rather than rejected — the
        // pre-strict behaviour the toggle restores.
        $response = $this->handle('/albums/1?include=tracks&relatedQuery[tracks][sort]=-duration');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:fetching')]
    #[Group('spec:errors')]
    public function anAllLowercaseFamilyStillFailsTheAlwaysOnBaselineEvenWhenRelaxed(): void
    {
        // The relax toggle stands down only the strict superset, never core's always-on
        // spec baseline. `pag[number]=2` (base `pag`, all-a-z) violates the custom-param
        // naming rule the spec mandates a 400 for, so it is a 400 even relaxed — the
        // toggle is not an escape hatch for a misspelled reserved family.
        $response = $this->handle('/albums?pag[number]=2');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $first['code'] ?? null);
        self::assertSame(['parameter' => 'pag'], $first['source'] ?? null);
    }
}
