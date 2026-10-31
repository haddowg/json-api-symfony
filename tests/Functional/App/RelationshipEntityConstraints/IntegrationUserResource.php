<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationshipEntityConstraints;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Validation\Constraint\NativeEntityConstraints;
use haddowg\JsonApiBundle\Validation\DeclaresEntityConstraintsInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * An integration user needs a primary company and one or two companies. The name
 * carries an attribute constraint that some stored users break, which a
 * relationship write must not re-check.
 */
class IntegrationUserResource extends AbstractResource implements DeclaresEntityConstraintsInterface
{
    public static string $type = 'integration-users';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->maxLength(8),
            BelongsTo::make('primaryCompany', 'companies'),
            BelongsToMany::make('companies', 'companies'),
        ];
    }

    public function entityConstraints(): array
    {
        return [
            NativeEntityConstraints::make(new Callback(static function (mixed $user, ExecutionContextInterface $context): void {
                if (!$user instanceof IntegrationUser) {
                    return;
                }
                if ($user->primaryCompany === null) {
                    $context->buildViolation('An integration user needs a primary company.')->atPath('primaryCompany')->addViolation();
                }
                $companies = \count([...$user->companies]);
                if ($companies === 0) {
                    $context->buildViolation('An integration user needs at least one company.')->atPath('companies')->addViolation();
                }
                if ($companies > 2) {
                    $context->buildViolation('An integration user may have at most two companies.')->atPath('companies')->addViolation();
                }
            })),
        ];
    }
}
