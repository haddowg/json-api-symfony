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
use Symfony\Component\Security\Core\Exception\InvalidArgumentException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Judges a caller against an operation's security rule with Symfony Security, the way the
 * bundle enforces it, without performing the operation.
 *
 * Each of the operation's additional gates is judged the same way as its primary rule,
 * and one that denies the caller makes the operation {@see SecurityVerdict::Denied}
 * whatever the others answer.
 *
 * A rule that reads the resource ({@see SecurityLevel::Entity}) is
 * {@see SecurityVerdict::Depends}. No rule, or `false`, is
 * {@see SecurityVerdict::Allowed}. An expression is evaluated with no resource, and
 * `true`, which a firewall enforces, needs an authenticated caller. Without a user the
 * authorization checker judges the current caller. With one, it judges that user through
 * `isGrantedForUser()` on Symfony 7.3 and later, and before that with a token for the user
 * swapped into the token storage for the call, so an `is_granted()` inside the
 * expression sees the same user. A rule that asks whether the user is authenticated also
 * takes the swapped token on 7.3 and later, since the offline token `isGrantedForUser()`
 * uses can't answer it.
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
        $verdict = $this->judge($operation->security, $operation->securityLevel, $user);
        foreach ($operation->additionalGates as $gate) {
            if ($verdict === SecurityVerdict::Denied) {
                break;
            }
            $part = $this->judge($gate->expression, $gate->level, $user);
            if ($part !== SecurityVerdict::Allowed) {
                $verdict = $part;
            }
        }

        return $verdict;
    }

    private function judge(string|bool|null $rule, SecurityLevel $level, ?object $user): SecurityVerdict
    {
        if ($level === SecurityLevel::Entity) {
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
            try {
                return $checker->isGrantedForUser($user, $attribute);
            } catch (InvalidArgumentException $e) {
                // The offline token it judges with can't answer whether the user is authenticated.
                if ($this->tokenStorage === null) {
                    throw $e;
                }
            }
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
