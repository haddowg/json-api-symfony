<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ErrorLocalizationTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * End-to-end witness that the bundle localizes core's error catalogue through the
 * Symfony translator (bundle ADR 0115). Under a non-default locale (`fr`) with a
 * `jsonapi_errors.fr.yaml` catalogue, real HTTP requests that trip a core catalogue
 * error and a validation `422` render French copy:
 *
 *  - a `404` localizes its `title` while its parameter-free `detail` falls back to
 *    core's English (per-slot resolution);
 *  - a `400` localizes both its `title` and its `detail`, the French template's
 *    `{param}` token interpolated from the error's context — localization AND
 *    interpolation, end to end;
 *  - a `422` the validator builds (`VALIDATION_FAILED`) localizes its `title` through
 *    core's uniform resolver reach.
 *
 * A single in-memory kernel: the error render path is provider-agnostic (core's
 * `ErrorResponse` + the resolver, no data layer), so a Doctrine twin would exercise
 * the identical path for no added coverage.
 */
final class ErrorLocalizationTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return ErrorLocalizationTestKernel::class;
    }

    #[Test]
    #[Group('errors')]
    public function aCatalogueErrorLocalizesItsTitleAndFallsBackPerSlotForAnUntranslatedDetail(): void
    {
        $response = $this->handle('/articles/999');

        self::assertSame(404, $response->getStatusCode());
        $error = $this->firstError($response);

        // code + status are the machine/HTTP contract — untouched.
        self::assertSame('RESOURCE_NOT_FOUND', $error['code']);
        self::assertSame('404', $error['status']);
        // The title is localized from jsonapi_errors.fr.yaml…
        self::assertSame('Ressource introuvable', $error['title']);
        // …while the detail — which the catalogue does not translate — falls back to
        // core's English default (per-slot resolution).
        self::assertSame('The requested resource is not found!', $error['detail']);
    }

    #[Test]
    #[Group('errors')]
    public function aCatalogueErrorLocalizesTitleAndInterpolatesTheDetailTemplate(): void
    {
        // Strict query parameters reject an unknown family with a 400 whose context
        // carries the offending name — a clean single param.
        $response = $this->handle('/articles?bogus=1');

        self::assertSame(400, $response->getStatusCode());
        $error = $this->firstError($response);

        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $error['code']);
        self::assertSame('Paramètre de requête non reconnu', $error['title']);
        // The French detail template's {param} token is filled from the error context.
        self::assertSame(
            "Le paramètre de requête 'bogus' n'est pas reconnu par le point de terminaison.",
            $error['detail'],
        );
    }

    #[Test]
    #[Group('errors')]
    public function theValidation422TitleLocalizesThroughTheUniformResolverReach(): void
    {
        // A create missing the required `title` is a 422 the validator builds with code
        // VALIDATION_FAILED; core applies the resolver to it uniformly, so its title
        // localizes through the very same catalogue.
        $response = $this->handle('/articles', 'POST', [
            'data' => ['type' => 'articles', 'attributes' => ['body' => 'Sans titre.', 'category' => 'news']],
        ]);

        self::assertSame(422, $response->getStatusCode());
        $error = $this->firstError($response);

        self::assertSame('VALIDATION_FAILED', $error['code']);
        self::assertSame('Entité non traitable', $error['title']);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstError(Response $response): array
    {
        $body = $this->decode($response);
        self::assertArrayHasKey('errors', $body);
        self::assertIsArray($body['errors']);
        self::assertArrayHasKey(0, $body['errors']);
        $error = $body['errors'][0];
        self::assertIsArray($error);

        /** @var array<string, mixed> $error */
        return $error;
    }
}
