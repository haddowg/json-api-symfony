<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\Resource\ProvidesRelationAuthorizerInterface;
use haddowg\JsonApiBundle\Security\RelationAuthorizer;

/**
 * The name of the configured server a core server object was built as, for code that
 * holds the server object but not its name.
 */
final class ServerName
{
    /**
     * The name `$server` was built under by its {@see ServerFactory}, which binds the
     * server's relation authorizer to that name, or the implicit `default` server when
     * the object was not built by a factory.
     */
    public static function of(object $server): string
    {
        $authorizer = $server instanceof ProvidesRelationAuthorizerInterface ? $server->relationAuthorizer() : null;

        return $authorizer instanceof RelationAuthorizer ? $authorizer->serverName() : ServerProvider::DEFAULT_SERVER;
    }
}
