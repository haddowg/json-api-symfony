<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation\Constraint;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApi\Resource\Constraint\ProvidesJsonSchema;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * The escape hatch for a validation rule core's constraint vocabulary does not model:
 * wrap one or more **native Symfony** `Constraint` objects and attach them to a field
 * (or filter) with core's `constrain()`.
 *
 * ```php
 * Str::make('slug')->constrain(NativeConstraints::make([new Assert\NotCompromisedPassword()]));
 * ```
 *
 * Unlike defining a bespoke {@see \haddowg\JsonApi\Resource\Constraint\ConstraintInterface}
 * value object plus a class-keyed {@see \haddowg\JsonApiBundle\Validation\ConstraintTranslatorInterface},
 * this needs no translator: the {@see \haddowg\JsonApiBundle\Validation\ConstraintTranslator}
 * recognises it and passes the wrapped `Constraint`s straight to Symfony's validator, so
 * they run in the same `422`-with-`source.pointer` pass as the translated core rules — on
 * write bodies and, because the filter-value validator shares the translator, on
 * `filter[…]` values too. The trade-off is portability: a `NativeConstraints` couples the
 * field to Symfony, so prefer a core constraint when one exists and reach here only for a
 * genuinely Symfony-native rule.
 *
 * **Schema is opt-in.** A native rule is invisible to the generated OpenAPI / JSON Schema
 * by default (`contribute()` returns the schema unchanged), so it validates without
 * documenting. Declare the value schema it implies with {@see schema()} — a closure over
 * core's neutral {@see Schema} VO — when you want it in the document; keep it a neutral,
 * framework-independent fragment so a byte-compatible twin (e.g. the Laravel `LaravelRules`
 * carrier) can emit the identical schema.
 *
 * Scope it to a write context with {@see onCreate()} / {@see onUpdate()} (the default
 * applies on both); `constrain()` does not re-stamp the context, matching every other
 * custom constraint.
 */
final readonly class NativeConstraints implements ProvidesJsonSchema
{
    /**
     * @var list<SymfonyConstraint>
     */
    public array $constraints;

    /**
     * @var \Closure(Schema): Schema|null
     */
    private ?\Closure $schema;

    /**
     * @param SymfonyConstraint|list<SymfonyConstraint> $constraints
     */
    public function __construct(
        SymfonyConstraint|array $constraints,
        public Context $context = new Context(),
        ?\Closure $schema = null,
    ) {
        $this->constraints = \is_array($constraints) ? \array_values($constraints) : [$constraints];
        $this->schema = $schema;
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
        return new self($this->constraints, Context::onlyCreate(), $this->schema);
    }

    public function onUpdate(): self
    {
        return new self($this->constraints, Context::onlyUpdate(), $this->schema);
    }

    /**
     * Declare the OpenAPI value schema this native rule implies. The closure receives
     * the field's accumulated {@see Schema} and returns it augmented
     * (`fn (Schema $s) => $s->withMinLength(3)`); without it the rule contributes
     * nothing to the document.
     *
     * @param \Closure(Schema): Schema $schema
     */
    public function schema(\Closure $schema): self
    {
        return new self($this->constraints, $this->context, $schema);
    }

    public function context(): Context
    {
        return $this->context;
    }

    public function contribute(Schema $schema): Schema
    {
        return $this->schema !== null ? ($this->schema)($schema) : $schema;
    }
}
