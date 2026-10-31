<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Catalog;

use haddowg\JsonApi\Catalog\OperationDefinition;
use haddowg\JsonApi\Catalog\SecurityEvaluatorInterface;
use haddowg\JsonApi\Catalog\SecurityLevel;
use haddowg\JsonApi\Catalog\SecurityVerdict;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Judges a caller against an operation's security rule with Symfony Security, the way the
 * bundle enforces it, without performing the operation.
 *
 * A rule that reads the resource ({@see SecurityLevel::Entity}) is
 * {@see SecurityVerdict::Depends}. No rule, or `false`, is
 * {@see SecurityVerdict::Allowed}. An expression is evaluated with no resource, and
 * `true`, which a firewall enforces, needs an authenticated caller. Without a user the
 * authorization checker judges the current caller. With one, it judges that user through
 * `isGrantedForUser()` on Symfony 7.3 and later, and before that with a token for the user
 * swapped into the token storage for the call, so an `is_granted()` inside the
 * expression sees the same user.
 */
final class ExpressionSecurityEvaluator implements SecurityEvaluatorInterface
{
    private const string FIREWALL = 'json_api_catalog';

    public function __construct(
        private readonly ?AuthorizationCheckerInterface $authorizationChecker = null,
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {}

    /**
     * @param UserInterface|null $user the caller to judge; null judges the current one
     *
     * @throws \InvalidArgumentException when `$user` is not a Symfony {@see UserInterface}
     * @throws \LogicException           when a rule needs judging and Symfony Security is not configured
     */
    public function evaluate(OperationDefinition $operation, ?object $user = null): SecurityVerdict
    {
        $rule = $operation->security;
        if ($operation->securityLevel === SecurityLevel::Entity) {
            return SecurityVerdict::Depends;
        }
        if ($rule === null || $rule === false) {
            return SecurityVerdict::Allowed;
        }

        $attribute = $rule === true ? AuthenticatedVoter::IS_AUTHENTICATED : new Expression($rule);

        return $this->isGranted($attribute, $user) ? SecurityVerdict::Allowed : SecurityVerdict::Denied;
    }

    private function isGranted(string|Expression $attribute, ?object $user): bool
    {
        $checker = $this->authorizationChecker ?? throw new \LogicException('Judging an operation\'s security needs symfony/security-bundle with a firewall configured.');
        if ($user === null) {
            return $checker->isGranted($attribute);
        }

        if (!$user instanceof UserInterface) {
            throw new \InvalidArgumentException(\sprintf('The user to judge an operation for must be a %s, got %s.', UserInterface::class, \get_debug_type($user)));
        }
        if ($checker instanceof UserAuthorizationCheckerInterface) {
            return $checker->isGrantedForUser($user, $attribute);
        }

        $storage = $this->tokenStorage ?? throw new \LogicException('Judging an operation\'s security for a given user needs symfony/security-bundle with a firewall configured.');
        $previous = $storage->getToken();
        $storage->setToken(new UsernamePasswordToken($user, self::FIREWALL, $user->getRoles()));

        try {
            return $checker->isGranted($attribute);
        } finally {
            $storage->setToken($previous);
        }
    }
}
