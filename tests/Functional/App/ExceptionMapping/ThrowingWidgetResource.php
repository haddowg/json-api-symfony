<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksInterface;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksTrait;

/**
 * The `throwingWidgets` resource — the exception-mapping harness's throw site
 * (bundle ADR 0073). A `GET /throwingWidgets?throwSignal=<signal>` collection read
 * reaches its {@see afterFetchCollection()} hook, which throws a chosen test exception
 * on a JSON:API route so the next phase can assert how each is rendered:
 *
 *  - `?throwSignal=config`  → {@see ConfigMappedException} (mapped by the kernel's
 *    `json_api.exceptions` config map);
 *  - `?throwSignal=mapper`  → {@see MapperMappedException} (mapped by the tagged
 *    {@see TestExceptionMapper} to a rich error);
 *  - `?throwSignal=jsonapi` → {@see NativeJsonApiException} (a core
 *    JsonApiExceptionInterface — the invariant case: always rendered natively, never
 *    via a mapper);
 *  - no/other `throwSignal` → the collection renders normally.
 *
 * The `throwSignal` family is camelCased (so it clears the spec's always-on
 * custom-parameter naming baseline, which reserves all-lowercase names), and the
 * kernel registers `json_api.strict_query_parameters: false`, so it is silently
 * accepted rather than rejected as an unrecognized family before the read hook fires.
 */
final class ThrowingWidgetResource extends AbstractResource implements ResourceLifecycleHooksInterface
{
    use ResourceLifecycleHooksTrait;

    public static string $type = 'throwingWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }

    public function afterFetchCollection(array $items, HookContext $context): ?DataResponse
    {
        $signal = $context->request->getQueryParam('throwSignal');

        match ($signal) {
            'config' => throw new ConfigMappedException('domain failure mapped by config'),
            'mapper' => throw new MapperMappedException('domain failure mapped by a tagged mapper'),
            'both' => throw new BothMappedException('domain failure matched by both facets'),
            'unmapped' => throw new UnmappedException('domain failure matched by neither facet'),
            'jsonapi' => throw new NativeJsonApiException(),
            default => null,
        };

        return null;
    }
}
