<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Security;

/**
 * Thrown at container build or cache warm-up when a resource, relation or action
 * declares a security expression the application cannot evaluate (bundle ADR 0043).
 * A declared expression must either be enforced or stop the build; it never passes
 * silently.
 */
final class UnenforceableSecurityException extends \LogicException
{
    public const string EXPRESSION_LANGUAGE = 'symfony/expression-language is not installed (composer require symfony/expression-language)';

    public const string SECURITY_CORE = 'symfony/security-core is not installed (composer require symfony/security-core)';

    public const string AUTHORIZATION_CHECKER = 'the security.authorization_checker service is not defined (register SecurityBundle and configure a firewall)';

    /**
     * @param non-empty-list<string> $declarations where each enforced expression is declared, e.g. `type "widgets" (securityCreate)`
     * @param non-empty-list<string> $missing      what the application lacks to evaluate them
     */
    public static function for(array $declarations, array $missing): self
    {
        return new self(\sprintf(
            'Declared JSON:API security cannot be enforced: %s. It is declared on %s. '
            . 'Install and configure the missing pieces, or declare security as true '
            . '(documented as secured, enforced by your own firewall) or false (documented as public) instead of an expression.',
            \implode('; ', $missing),
            \implode(', ', $declarations),
        ));
    }

    /**
     * What this application lacks to evaluate a security expression, empty when it can.
     *
     * @return list<string>
     */
    public static function missingPieces(bool $expressionLanguage, bool $securityCore, bool $authorizationChecker): array
    {
        $missing = [];
        if (!$expressionLanguage) {
            $missing[] = self::EXPRESSION_LANGUAGE;
        }
        if (!$securityCore) {
            $missing[] = self::SECURITY_CORE;
        }
        if (!$authorizationChecker) {
            $missing[] = self::AUTHORIZATION_CHECKER;
        }

        return $missing;
    }
}
