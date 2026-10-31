<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation\Constraint;

use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApiBundle\Validation\EntityConstraintInterface;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * The class-level counterpart of {@see NativeConstraints}: wraps ready-made Symfony
 * constraints that validate the whole hydrated entity, such as an application's own
 * class constraint or `Callback`, so they run without a translator.
 *
 * Return it from {@see \haddowg\JsonApiBundle\Validation\DeclaresEntityConstraintsInterface::entityConstraints()}
 * on the resource. It runs with the other entity-level constraints after hydration
 * and after a relationship endpoint changes the resource, and each violation is a `422`: at `/data` when it has no property path, at the
 * relationship when the path names one, and at the attribute otherwise.
 *
 * ```php
 * public function entityConstraints(): array
 * {
 *     return [NativeEntityConstraints::make(new AdvertiserLimit())->onCreate()];
 * }
 * ```
 */
final readonly class NativeEntityConstraints implements EntityConstraintInterface
{
    /**
     * @var list<SymfonyConstraint>
     */
    public array $constraints;

    /**
     * @param SymfonyConstraint|list<SymfonyConstraint> $constraints
     */
    public function __construct(
        SymfonyConstraint|array $constraints,
        public Context $context = new Context(),
    ) {
        $this->constraints = \is_array($constraints) ? \array_values($constraints) : [$constraints];
    }

    /**
     * @param SymfonyConstraint|list<SymfonyConstraint> $constraints
     */
    public static function make(SymfonyConstraint|array $constraints): self
    {
        return new self($constraints);
    }

    public function onCreate(): self
    {
        return new self($this->constraints, Context::onlyCreate());
    }

    public function onUpdate(): self
    {
        return new self($this->constraints, Context::onlyUpdate());
    }

    public function context(): Context
    {
        return $this->context;
    }
}
