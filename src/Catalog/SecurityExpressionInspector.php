<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Catalog;

use Symfony\Component\ExpressionLanguage\Node\NameNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\Parser;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Symfony\Component\Security\Core\Authorization\ExpressionLanguage;

/**
 * Tells whether a security expression reads the resource it guards, through the `object`
 * or `subject` variable, so it cannot be judged before the resource is loaded.
 *
 * @internal
 */
final class SecurityExpressionInspector
{
    /** The variables Symfony's expression voter evaluates an expression with. */
    private const array VARIABLES = ['token', 'user', 'object', 'subject', 'role_names', 'request', 'trust_resolver', 'auth_checker'];

    private const array RESOURCE_VARIABLES = ['object', 'subject'];

    private static ?ExpressionLanguage $language = null;

    public static function readsResource(string $expression): bool
    {
        self::$language ??= new ExpressionLanguage();

        try {
            // Newer versions can skip functions an app registered on its own language.
            $nodes = \defined(Parser::class . '::IGNORE_UNKNOWN_FUNCTIONS')
                ? self::$language->parse($expression, self::VARIABLES, Parser::IGNORE_UNKNOWN_FUNCTIONS)->getNodes()
                : self::$language->parse($expression, self::VARIABLES)->getNodes();
        } catch (SyntaxError) {
            return \preg_match('/\b(?:object|subject)\b/', $expression) === 1;
        }

        return self::references($nodes);
    }

    private static function references(Node $node): bool
    {
        if ($node instanceof NameNode && \in_array($node->attributes['name'] ?? null, self::RESOURCE_VARIABLES, true)) {
            return true;
        }

        foreach ($node->nodes as $child) {
            if ($child instanceof Node && self::references($child)) {
                return true;
            }
        }

        return false;
    }
}
