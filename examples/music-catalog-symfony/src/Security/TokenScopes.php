<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Security;

use haddowg\JsonApi\Scope\GrantedScopesProviderInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads the scopes an example access token grants. The token is the user identifier,
 * optionally followed by `#` and the space-separated scopes: `Bearer ada#catalog:write`.
 * A token without `#` is unscoped, so its requests are not checked for scopes, and
 * `Bearer ada#` grants none.
 */
final class TokenScopes implements GrantedScopesProviderInterface
{
    public function granted(ServerRequestInterface $request): ?array
    {
        $header = $request->getHeaderLine('Authorization');
        if (!\str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $separator = \strpos($header, '#');
        if ($separator === false) {
            return null;
        }

        return \array_values(\array_filter(\explode(' ', \substr($header, $separator + 1)), static fn(string $scope): bool => $scope !== ''));
    }
}
