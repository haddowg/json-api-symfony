# OAuth scopes

An API that hands out OAuth access tokens usually wants each token limited to what it was
granted: a token with `catalog:read` can browse albums but not edit them. The bundle checks
that for you on every JSON:API route, on the include tree and on each operation of an
Atomic Operations batch, and lists the scopes in the OpenAPI document. The check itself
lives in core; see core's [scopes guide](https://github.com/haddowg/json-api/blob/main/docs/scopes.md)
for the rules it applies.

Scopes only restrict. Your firewall still authenticates the caller, and your
[security expressions](authorization.md) still run after the scope check passes.

## The four services

Write a class for each contract you need and register it as a service. With
autoconfiguration on, the bundle finds each one by the core interface it implements; if
you have two implementations of one interface, alias the interface to the one to use.

| Implement | To | Without it |
|---|---|---|
| `haddowg\JsonApi\Scope\GrantedScopesProviderInterface` | read the scopes the request's token grants | nothing is enforced |
| `haddowg\JsonApi\Scope\OperationScopeResolverInterface` | say which scopes each operation needs | only the attribute overrides below apply |
| `haddowg\JsonApi\Scope\ScopeMatcherInterface` | decide whether granted scopes cover a required one | exact string match |
| `haddowg\JsonApi\Scope\ScopeCatalogInterface` | describe every scope for the OpenAPI document | the document can't describe them, and `--strict` export fails |

The grant provider receives the PSR-7 request the bundle already built. Return `null` for a
request with no scoped token, such as a session login, and that request is not checked.
Return `[]` for a token that grants nothing.

```php
use haddowg\JsonApi\Scope\GrantedScopesProviderInterface;
use Psr\Http\Message\ServerRequestInterface;

final class TokenScopes implements GrantedScopesProviderInterface
{
    public function __construct(private readonly TokenIntrospector $tokens) {}

    public function granted(ServerRequestInterface $request): ?array
    {
        $token = $this->tokens->fromRequest($request);

        return $token?->scopes;
    }
}
```

The resolver is asked once per operation when the table is built. Its key names the type,
the kind of operation, the HTTP method, and for an action the safety it declares:

```php
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;

final class CatalogScopes implements OperationScopeResolverInterface
{
    public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
    {
        if ($operation->type !== 'albums') {
            return [];
        }

        return match ($operation->kind) {
            OperationKind::FetchOne, OperationKind::FetchCollection => ['catalog:read'],
            OperationKind::Action => $operation->safety?->readOnly === true ? ['catalog:read'] : ['catalog:write'],
            default => ['catalog:write'],
        };
    }
}
```

The type metadata the bundle passes implements core's `DeclaresResourceClass`, so a
resolver can read attributes of your own off the resource class.

## Overriding one operation

An attribute beats the resolver. `[]` means the operation needs no scope.

```php
#[AsJsonApiResource(operationScopes: [
    'delete' => ['catalog:write', 'catalog:admin'],
    'fetchRelated.tracks' => [],
])]
final class AlbumResource extends AbstractResource { /* ... */ }

#[AsJsonApiAction(type: 'albums', path: 'artwork', scopes: ['catalog:write', 'catalog:media'])]
final class UploadAlbumArtwork implements ActionHandlerInterface { /* ... */ }
```

`operationScopes` is keyed exactly like [`operationExamples`](openapi.md#operation-examples),
and a standalone serializer takes it on `#[AsJsonApiSerializer]` too. You may name
`fetchOne` and `fetchCollection` on a type that exposes neither, because an `?include`
reaching the type is checked against them. Any other write the type doesn't expose fails
the container build.

The Atomic Operations endpoint's own scopes come from config:

```yaml
json_api:
    atomic_operations:
        enabled: true
        scopes: [batch]
```

## What a related read and an include need

A related read, `GET /albums/1/tracks` or its relationship endpoint, needs its own scopes
plus the read scopes of what it returns. Each hop of an `?include` needs the read scopes of
the type it reaches: a refused hop is a `403` naming the path. A resource included only by
default that the caller can't read is left out of `included`, and the request succeeds.

## The error

A token missing a scope gets a `403`:

```http
HTTP/1.1 403 Forbidden
WWW-Authenticate: Bearer error="insufficient_scope", scope="catalog:write"
Content-Type: application/vnd.api+json

{"errors":[{"status":"403","code":"INSUFFICIENT_SCOPE","title":"Insufficient scope",
  "detail":"The access token does not grant every scope this request needs.",
  "meta":{"requiredScopes":["catalog:write"],"missingScopes":["catalog:write"]}}]}
```

The exception listener renders it like any core error, header included, and on the atomic
route the response still advertises the atomic extension.

## Atomic Operations

The endpoint's own scopes are checked first. Then, before the batch opens a transaction,
every operation in it is checked against the batch request's grant: an `add` as a create,
an `update` as an update, a `remove` as a delete, or the matching relationship operation
when it targets one. The first operation that fails refuses the whole batch with
`source.pointer` `/atomic:operations/<index>`, and nothing is written.

## Building and caching the table

A cache warmer builds each server's table when the container is compiled and on
`cache:warmup`, and writes it to `var/cache/<env>/json_api/scope_tables.php`. The warmer is
not optional, so these fail the build rather than a request:

- the resolver throws;
- an override names an operation or relation the type doesn't have;
- a scope isn't printable ASCII, or contains a space, a double quote or a backslash;
- a public operation, one whose security is `false`, needs a scope, or a public related
  read returns a type whose reads need one.

If the file is missing, the bundle builds the table from your metadata on first use. If
an operation is missing from it, for instance after a deploy that skipped the warmup, the
enforcer resolves that operation on the spot and logs a warning. It never lets a request
through because the table is stale.

## The OpenAPI document

Set the scheme the scopes are listed under, an `oauth2` one so its flows can list them:

```yaml
json_api:
    openapi:
        security:
            schemes:
                oauth2:
                    type: oauth2
                    flows:
                        clientCredentials:
                            tokenUrl: 'https://api.example.com/oauth/token'
            default_requirement: [bearer]
            scope_scheme: oauth2
```

Each operation that needs a scope then reads `security: [{oauth2: [scopes]}]`, and the
flows list your scope catalogue after any scopes the config declares. A document where no
operation needs a scope is unchanged. Scopes arrived in contract 16, but every document
now carries 18, the baseline since the links components were shared.

`json-api:openapi:export` warns about any required scope neither the catalogue nor the
flows describe, and `--strict` fails on them. A `scope_scheme` that isn't `oauth2` is a
warning only.

## Listing what a caller can do

The `haddowg\JsonApi\Catalog\OperationCatalog` service lists every operation of a server,
in the order the OpenAPI document does, with its path, the security rule guarding it and
the scopes it needs. `ScopeChecker` compares a grant with those scopes using the same
matcher enforcement does, and `SecurityEvaluatorInterface` judges a user against the rule
through Symfony Security:

```php
use haddowg\JsonApi\Catalog\OperationCatalog;
use haddowg\JsonApi\Catalog\SecurityEvaluatorInterface;
use haddowg\JsonApi\Catalog\SecurityVerdict;
use haddowg\JsonApi\Scope\ScopeChecker;

$callable = [];
foreach ($catalog->forServer('default') as $operation) {
    if ($checker->satisfies($operation, $grantedScopes)
        && $evaluator->evaluate($operation, $user) !== SecurityVerdict::Denied) {
        $callable[] = $operation->operationId;
    }
}
```

The rule is the one the bundle enforces: a type's `security*` declaration, a relation's
read or mutate security over its parent's, or an action's `security`. An expression that
reads `object` or `subject` needs the resource, so the evaluator answers `Depends`. Any
other expression is evaluated now, for the current caller when you pass no user, or for
the user you pass. `true`, a firewall-enforced operation, needs an authenticated caller,
and `false` or no rule is `Allowed`.
