<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Scopes;

use haddowg\JsonApi\Scope\GrantedScopesProviderInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Grants the space-separated scopes in the `X-Granted-Scopes` header. A request without
 * the header carries no scoped token, so it is not checked.
 */
final class HeaderGrantedScopes implements GrantedScopesProviderInterface
{
    public const string HEADER = 'X-Granted-Scopes';

    public function granted(ServerRequestInterface $request): ?array
    {
        if (!$request->hasHeader(self::HEADER)) {
            return null;
        }

        return \array_values(\array_filter(\explode(' ', $request->getHeaderLine(self::HEADER)), static fn(string $scope): bool => $scope !== ''));
    }
}
