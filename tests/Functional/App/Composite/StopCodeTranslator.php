<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Composite;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApiBundle\Validation\ConstraintTranslatorInterface;
use Symfony\Component\Validator\Constraints\Regex;

final class StopCodeTranslator implements ConstraintTranslatorInterface
{
    public function supports(ConstraintInterface $constraint): bool
    {
        return $constraint instanceof StopCode;
    }

    public function translate(ConstraintInterface $constraint): array
    {
        return [new Regex(pattern: '/^[A-Z]{3}$/', message: 'This value is not a valid stop code.')];
    }
}
