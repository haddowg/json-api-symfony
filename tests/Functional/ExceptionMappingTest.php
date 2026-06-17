<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMappingTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The extensible exception → JSON:API-error mapping seam (gap G15 / bundle ADR
 * 0073). The {@see ExceptionMappingTestKernel} serves a `throwingWidgets` type
 * whose read hook throws a chosen test exception on a JSON:API route
 * (`GET /throwingWidgets?throwSignal=<signal>`); these tests drive each signal and assert
 * how the {@see \haddowg\JsonApiBundle\EventListener\ExceptionListener} renders it.
 *
 * The five facts under test:
 *  - CONFIG MAP — a plain domain exception named in `json_api.exceptions` renders
 *    with the configured status (the config-driven ConfiguredExceptionMapper);
 *  - TAGGED MAPPER — a tagged {@see \haddowg\JsonApiBundle\EventListener\ExceptionMapperInterface}
 *    renders its own rich {@see \haddowg\JsonApi\Response\ErrorResponse} (custom
 *    status + source + meta), proving full control;
 *  - THE INVARIANT — a core {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}
 *    always renders natively, even when a mapper and the config map both match it;
 *  - ORDERING — for a class both a tagged mapper and the config map match, the
 *    higher-priority tagged mapper wins over the low-priority config map;
 *  - FALLBACK — an unmapped, non-JSON:API throwable still renders the generic 500.
 */
final class ExceptionMappingTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return ExceptionMappingTestKernel::class;
    }

    /**
     * Case 1 — CONFIG MAP: the `json_api.exceptions` config map points the plain
     * domain {@see \haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping\ConfigMappedException}
     * at `402`, so the throwable renders as a JSON:API error document with that
     * status and the JSON:API media type.
     */
    #[Test]
    #[Group('spec:errors')]
    public function aConfigMappedExceptionRendersWithTheConfiguredStatus(): void
    {
        $response = $this->handle('/throwingWidgets?throwSignal=config');

        self::assertSame(402, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $document = $this->decode($response);

        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $firstError = $errors[0] ?? null;
        self::assertIsArray($firstError);
        self::assertSame('402', $firstError['status'] ?? null);
        // The config-driven mapper carries the shared reason-phrase title (402 is
        // outside HttpReasonPhrase's table, so the generic 'Error'); nothing leaks
        // into the document beyond the status-keyed error.
        self::assertSame('Error', $firstError['title'] ?? null);
    }

    /**
     * Case 2 — TAGGED MAPPER: the auto-tagged
     * {@see \haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping\TestExceptionMapper}
     * maps a {@see \haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping\MapperMappedException}
     * to a **rich** ErrorResponse — a custom status, a stable `code`, a
     * `source.pointer`, and `meta` — proving a mapper has full control over the
     * rendered error, beyond the status-only config map.
     */
    #[Test]
    #[Group('spec:errors')]
    public function aTaggedMapperRendersItsRichErrorResponse(): void
    {
        $response = $this->handle('/throwingWidgets?throwSignal=mapper');

        self::assertSame(418, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $document = $this->decode($response);

        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        $firstError = $errors[0] ?? null;
        self::assertIsArray($firstError);

        self::assertSame('418', $firstError['status'] ?? null);
        self::assertSame('TEAPOT', $firstError['code'] ?? null);
        self::assertSame('I am a teapot', $firstError['title'] ?? null);
        // The rich members a status-only config map cannot express:
        $source = $firstError['source'] ?? null;
        self::assertIsArray($source);
        self::assertSame('/data/attributes/brew', $source['pointer'] ?? null);

        $meta = $firstError['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertSame('TestExceptionMapper', $meta['mappedBy'] ?? null);
    }

    /**
     * Case 3 — THE INVARIANT (the seam's headline guarantee): a core
     * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} renders natively
     * through its own `getErrors()` / `getStatusCode()`, **even though** the kernel
     * registers a tagged mapper that would map it (to a sentinel `599`) and a
     * `json_api.exceptions` entry that would map it (also `599`). The listener never
     * consults a mapper or the config map for a core exception, so the native `418`
     * and `NATIVE_TEAPOT` code win — if the seam ever broke, the `599` sentinel
     * would surface and fail this test.
     */
    #[Test]
    #[Group('spec:errors')]
    public function aCoreJsonApiExceptionAlwaysRendersNativelyDespiteAMatchingMapperAndConfig(): void
    {
        $response = $this->handle('/throwingWidgets?throwSignal=jsonapi');

        // Native status, not the 599 a mapper/config would have forced.
        self::assertSame(418, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $document = $this->decode($response);

        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        $firstError = $errors[0] ?? null;
        self::assertIsArray($firstError);

        self::assertSame('418', $firstError['status'] ?? null);
        // The exception's own getErrors() shape — proof it rendered natively.
        self::assertSame('NATIVE_TEAPOT', $firstError['code'] ?? null);
        self::assertSame('A native JSON:API exception', $firstError['title'] ?? null);

        // And explicitly: neither the mapper's nor the config's sentinel 599 surfaced.
        self::assertNotSame('599', $firstError['status'] ?? null);
    }

    /**
     * Case 4 — ORDERING: a
     * {@see \haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping\BothMappedException}
     * is matched by **both** the tagged mapper (default priority `0`, mapping it to a
     * rich `423`) and the config map (priority `-1000`, mapping it to `409`). The
     * higher-priority tagged mapper is consulted first and its non-null result wins,
     * so the `423` renders and the config's `409` never applies.
     */
    #[Test]
    #[Group('spec:errors')]
    public function theHigherPriorityTaggedMapperWinsOverTheConfigMap(): void
    {
        $response = $this->handle('/throwingWidgets?throwSignal=both');

        // 423 (the tagged mapper), not 409 (the config map).
        self::assertSame(423, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $document = $this->decode($response);

        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        $firstError = $errors[0] ?? null;
        self::assertIsArray($firstError);

        self::assertSame('423', $firstError['status'] ?? null);
        self::assertNotSame('409', $firstError['status'] ?? null);
        // The rich members confirm the mapper (not the status-only config) produced it.
        self::assertSame('LOCKED_BY_MAPPER', $firstError['code'] ?? null);
        $meta = $firstError['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertSame('TestExceptionMapper', $meta['mappedBy'] ?? null);
    }

    /**
     * Case 5 — FALLBACK UNCHANGED: an
     * {@see \haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping\UnmappedException}
     * that is not a core JSON:API exception, not an HttpException, not named in the
     * config map, and not claimed by any tagged mapper falls through every arm to the
     * listener's generic-500 — the mapping seam leaves the existing fallback intact.
     */
    #[Test]
    #[Group('spec:errors')]
    public function anUnmappedThrowableStillRendersTheGeneric500(): void
    {
        $response = $this->handle('/throwingWidgets?throwSignal=unmapped');

        self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $document = $this->decode($response);

        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        $firstError = $errors[0] ?? null;
        self::assertIsArray($firstError);

        self::assertSame('500', $firstError['status'] ?? null);
        self::assertSame('Internal Server Error', $firstError['title'] ?? null);
        // The kernel boots with debug off, so no message/meta leaks into the
        // redacted generic-500 form.
        self::assertArrayNotHasKey('detail', $firstError);
        self::assertArrayNotHasKey('meta', $firstError);
    }
}
