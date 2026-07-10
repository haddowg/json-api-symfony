<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\StrBuilder;

/**
 * The shared `badges` declaration both request-aware-predicate kernels serve: one
 * set of fields and relations, so the in-memory and Doctrine providers are
 * exercised by **identical** assertions and a failure localizes to the provider,
 * not the fixture (core ADRs 0079/0080, bundle ADR 0084).
 *
 * Every member declares a request-aware predicate keyed off the inbound `X-Role`
 * header (no security plumbing — {@see JsonApiRequestInterface} is a PSR-7 request,
 * so the predicate is provider-agnostic):
 *  - `secret` is **hidden** for a non-admin (`hidden(fn)`): present in an admin
 *    read, absent otherwise;
 *  - `writeOnlySecret` is unconditionally **write-only** via a closure
 *    (`writeOnly(fn => true)`): accepted on a write, rendered on no read — proving
 *    the closure path itself, not just the static flag;
 *  - `rank` is **read-only on update** for a non-admin (`readOnlyOnUpdate(fn)`): a
 *    non-admin PATCH of it is silently dropped, an admin PATCH applies;
 *  - `clearance` is **conditionally required** for an admin only
 *    (`when(fn($v, $req), fn($f) => $f->required())`): an admin omitting it 422s,
 *    a non-admin omitting it is accepted — proving the widened validation `when()`
 *    sees the request;
 *  - `medals` (to-many) is **gated for mutation** for a non-admin
 *    (`cannotReplace/cannotAdd/cannotRemove(fn)`): a non-admin PATCH/POST/DELETE to
 *    its relationship endpoint is a `403`, an admin's succeeds;
 *  - `secretMedals` (the same association) is **non-includable** for a non-admin
 *    (`cannotBeIncluded(fn)`): `?include=secretMedals` 400s for a non-admin, expands
 *    for an admin.
 *
 * The static getters stay permissive for a closure-declared member (the field is
 * not *unconditionally* restricted), so the superset OpenAPI schema, the
 * build-time relation lookups and the related-endpoint exposure are unaffected —
 * only the request-aware render/hydrate/validate/mutate/include paths honour the
 * predicate.
 */
abstract class BaseBadgeResource extends AbstractResource
{
    public static string $type = 'badges';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required()->minLength(2),
            // Hidden for a non-admin caller — present in an admin read, absent
            // otherwise.
            Str::make('secret')->hidden(
                static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
            ),
            // Unconditionally write-only, declared via the closure path so the
            // *resolver* (not just the static flag) is the thing under test: accepted
            // on a write, rendered on no read.
            Str::make('writeOnlySecret')->writeOnly(
                static fn(JsonApiRequestInterface $request): bool => true,
            ),
            // Read-only on update for a non-admin: an admin may PATCH it, a non-admin
            // PATCH of it is silently ignored (never hydrated, so never validated).
            Str::make('rank')->readOnlyOnUpdate(
                static fn(JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
            ),
            // Conditionally required for an admin only: the widened when() condition
            // branches on the caller, so an admin omitting `clearance` 422s while a
            // non-admin omitting it is accepted.
            Str::make('clearance')->nullable()->when(
                static fn(mixed $value, ?JsonApiRequestInterface $request): bool => $request?->getHeaderLine('X-Role') === 'admin',
                static function (StrBuilder $field): void {
                    $field->required();
                },
            ),
            // The to-many relation whose MUTATION is gated for a non-admin: a
            // PATCH (replace) / POST (add) / DELETE (remove) to its relationship
            // endpoint is a 403 for a non-admin, allowed for an admin.
            HasMany::make('medals', 'medals')->withData()
                ->cannotReplace(
                    static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
                )
                ->cannotAdd(
                    static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
                )
                ->cannotRemove(
                    static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
                ),
            // The same association, exposed as a separate relation whose INCLUDABILITY
            // is gated for a non-admin: `?include=secretMedals` 400s for a non-admin
            // and expands for an admin.
            HasMany::make('secretMedals', 'medals')->storedAs('medals')->withData()
                ->cannotBeIncluded(
                    static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
                ),
        ];
    }
}
