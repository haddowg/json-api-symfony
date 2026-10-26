<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EntityConstraints;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Validation\Constraint\NativeEntityConstraints;
use haddowg\JsonApiBundle\Validation\DeclaresEntityConstraintsInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Declares a class-level Symfony constraint without a translator: a brand may not be
 * named `Forbidden` (a violation on the whole brand) nor labelled `reserved` (one
 * pointing at the label), and only on create.
 */
class BrandResource extends AbstractResource implements DeclaresEntityConstraintsInterface
{
    public static string $type = 'brands';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Str::make('label')->nullable(),
            Boolean::make('archived'),
        ];
    }

    public function entityConstraints(): array
    {
        return [
            NativeEntityConstraints::make(new Callback(static function (mixed $brand, ExecutionContextInterface $context): void {
                if (!$brand instanceof Brand) {
                    return;
                }
                if ($brand->name === 'Forbidden') {
                    $context->buildViolation('This brand is not allowed.')->addViolation();
                }
                if ($brand->label === 'reserved') {
                    $context->buildViolation('This label is reserved.')->atPath('label')->addViolation();
                }
            }))->onCreate(),
        ];
    }
}
