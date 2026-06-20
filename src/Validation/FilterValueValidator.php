<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validates client-supplied `filter[<key>]` values against the **value
 * constraints** a filter declares ({@see FilterInterface::constraints()}, the
 * `numeric()` / `integer()` / `uuid()` / `boolean()` / `pattern()` / `constrain()`
 * builders on a value-carrying filter), *before* the filter reaches a data
 * provider. A violating value is a clean `400`
 * {@see FilterValueInvalid} (a bad query *parameter*, located by
 * `source.parameter` on `filter[<key>]`) — turning the provider's unhelpful
 * default for a mistyped value (a silent non-match in memory and on a loosely-typed
 * database, or a Doctrine PDO error — a `500` — on a strict driver) into a
 * deliberate client error.
 *
 * This is the filter-value twin of the {@see ResourceValidator}: it reuses the
 * same {@see ConstraintTranslator} bridge that gives a resource's attribute
 * constraints teeth, so the filter shortcuts need no new translator cases. Like
 * that bridge it is **optional** — wired only when `symfony/validator` is
 * installed; absent it the {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler}'s
 * injected validator is `null` and a constrained filter behaves exactly as
 * today (its constraints are inert), matching how the validator bridge degrades.
 *
 * Only the **client-supplied** values present in the request are validated, never
 * a filter's author-set `default()` — the handler hands this validator the raw
 * requested `filter` map, before core's `FilterDefaults::apply()` folds the
 * defaults in, so a server-declared default value is trusted and never re-checked.
 *
 * A filter's value may be a single scalar ({@see \haddowg\JsonApi\Resource\Filter\Where}),
 * a set — an array, or a delimited string for an `IN`-style filter
 * ({@see \haddowg\JsonApi\Resource\Filter\WhereIn} et al.) — or the structured
 * `{min?, max?}` of a {@see \haddowg\JsonApi\Resource\Filter\Range}/`DateRange`.
 * Each **scalar member** is validated against the constraints individually, so a
 * per-id rule like `integer()` applies to every member of `filter[id]=1,banana,3`,
 * and a `Range`'s per-bound rule applies to each present bound — an open
 * (blank/absent) bound is left unvalidated so `filter[price][max]=` does not `400`.
 */
final class FilterValueValidator
{
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly ConstraintTranslator $translator,
    ) {}

    /**
     * Validates each client-supplied filter value present in `$requested` against
     * the matching declared filter's value constraints, throwing on the first
     * filter whose value violates them.
     *
     * The `$requested` map is the **raw** request filter map
     * ({@see \haddowg\JsonApi\Operation\QueryParameters::$filter}) — not the
     * default-folded one — so an author-set `default()` is never validated. A
     * requested key with no matching declared filter is left untouched (the
     * unrecognised-key `400` is the provider's concern, raised later in the
     * {@see \haddowg\JsonApiBundle\DataProvider\CriteriaApplier}); a filter that
     * declares no constraints is skipped, so it costs nothing.
     *
     * @param array<string, mixed>  $requested the request's raw `filter[<key>]` map
     * @param list<FilterInterface> $filters   the declared filter vocabulary to match against
     *
     * @throws FilterValueInvalid when a client-supplied value violates its filter's constraints
     */
    public function validate(array $requested, array $filters): void
    {
        foreach ($filters as $filter) {
            $key = $filter->key();

            // Only a value the client actually sent is validated — a defaulted key
            // (absent from the request) is trusted; the default is folded in later.
            if (!\array_key_exists($key, $requested)) {
                continue;
            }

            $constraints = $this->symfonyConstraints($filter);
            if ($constraints === []) {
                // An unconstrained filter costs nothing. A DateRange always carries
                // its ISO-8601 shape Pattern, so it never reaches this short-circuit.
                continue;
            }

            $messages = [];
            foreach ($this->members($filter, $requested[$key]) as $member) {
                foreach ($this->validator->validate($member, $constraints) as $violation) {
                    $messages[] = (string) $violation->getMessage();
                }
            }

            // A DateRange's shape `Pattern` is deliberately lenient on the calendar
            // (it admits `1997-13-99`), so a present bound is additionally checked for
            // temporal validity — an unparseable date is a clean 400 here rather than
            // a silent, provider-divergent non-match in the data layer.
            $messages = [...$messages, ...$this->dateRangeMessages($filter, $requested[$key])];

            if ($messages !== []) {
                throw new FilterValueInvalid($key, $messages);
            }
        }
    }

    /**
     * The translated Symfony constraints enforcing a filter's declared value
     * constraints. Each core {@see \haddowg\JsonApi\Resource\Constraint\ConstraintInterface}
     * is run through the same {@see ConstraintTranslator} the attribute bridge uses.
     *
     * A filter's value constraints always apply (there is no create/update
     * document context for a query parameter), so — unlike the attribute bridge —
     * they are **not** filtered by `context()->appliesTo()`; each declared
     * constraint is translated and validated unconditionally.
     *
     * @return list<Constraint>
     */
    private function symfonyConstraints(FilterInterface $filter): array
    {
        $constraints = [];
        foreach ($filter->constraints() as $constraint) {
            // Filter-side validation passes no request to translate(): a widened
            // `when($value, $request)` condition on a filter constraint receives a
            // null request here (the documented MVP boundary, ADR 0084) — the
            // request-aware visibility/authz axis is the headline, filter-side
            // conditional vocabulary stays static for now.
            foreach ($this->translator->translate($constraint) as $symfonyConstraint) {
                $constraints[] = $symfonyConstraint;
            }
        }

        return $constraints;
    }

    /**
     * The scalar members of a filter value to validate individually: a single
     * scalar is wrapped as a one-element list; an `IN`-style set is each element of
     * the array, or — for a delimited string — each split-and-trimmed token. The
     * splitting mirrors the in-memory `ArrayFilterHandler`/Doctrine handlers, so a
     * per-member rule validates the exact tokens the provider would query against.
     * A `null` member (e.g. an empty token) is skipped — emptiness/presence is not
     * this validator's concern.
     *
     * @return list<mixed>
     */
    private function members(FilterInterface $filter, mixed $value): array
    {
        // A structured Range/DateRange carries a nested {min?, max?} value, not a
        // scalar or an IN-style set: validate each PRESENT bound individually so a
        // blank/absent bound (open-ended) is not validated and a malformed present
        // bound is rejected against the range's per-bound constraints.
        if ($filter instanceof \haddowg\JsonApi\Resource\Filter\Range) {
            return $this->rangeMembers($value);
        }

        if (\is_array($value)) {
            return \array_values(\array_filter($value, static fn(mixed $member): bool => $member !== null));
        }

        // An IN-style filter exposes a `delimiter` and carries a delimited string
        // ("1,2,3"); split it into the per-member tokens the provider would. A
        // plain Where carries a single scalar — validated whole.
        $delimiter = $this->delimiterFor($filter);
        if ($delimiter !== null && \is_string($value)) {
            $separator = $delimiter !== '' ? $delimiter : ',';

            return \array_values(\array_map('\trim', \explode($separator, $value)));
        }

        return [$value];
    }

    /**
     * The PRESENT, non-blank bounds of a {@see \haddowg\JsonApi\Resource\Filter\Range}
     * value — the nested `{min?, max?}` array. A blank (`''`) or absent bound is
     * open-ended, so it is treated as **absent** and never validated (matching the
     * in-memory and Doctrine `bound()` semantics — `filter[<key>][max]=` must not
     * `400`); each present bound is validated individually against the range's
     * per-bound constraints (a numeric `Pattern` for `Range`, an ISO-8601 `Pattern`
     * for `DateRange`), so a malformed bound is a clean `400`. A non-array value is a
     * no-op in both handlers, so there is nothing to validate.
     *
     * @return list<mixed>
     */
    private function rangeMembers(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $members = [];
        foreach (['min', 'max'] as $key) {
            if (!\array_key_exists($key, $value)) {
                continue;
            }

            /** @var mixed $bound */
            $bound = $value[$key];
            if ($bound === null || $bound === '') {
                continue;
            }

            $members[] = $bound;
        }

        return $members;
    }

    /**
     * Temporal-validity messages for a {@see \haddowg\JsonApi\Resource\Filter\DateRange}'s
     * present bounds. The range's shape `Pattern` is deliberately lenient on the
     * calendar (a regex cannot reject `1997-13-99` — month 13, day 99), so each
     * present bound is additionally run through the filter's own date deserializer
     * (the exact coercion the handlers apply); a bound that does not coerce to a
     * `\DateTimeInterface` is a calendar-invalid date and yields one message, so the
     * request is a clean `400` rather than a silent, provider-divergent non-match.
     * A non-`DateRange` filter (a numeric {@see \haddowg\JsonApi\Resource\Filter\Range}
     * or any scalar filter) contributes nothing — its shape `Pattern` is already a
     * complete check.
     *
     * @return list<string>
     */
    private function dateRangeMessages(FilterInterface $filter, mixed $value): array
    {
        if (!$filter instanceof \haddowg\JsonApi\Resource\Filter\DateRange) {
            return [];
        }

        $deserialize = $filter->deserialize;
        if ($deserialize === null) {
            return [];
        }

        $messages = [];
        foreach ($this->rangeMembers($value) as $bound) {
            if (!$deserialize($bound) instanceof \DateTimeInterface) {
                $messages[] = 'This value is not a valid date.';
            }
        }

        return $messages;
    }

    /**
     * The delimiter an `IN`-style filter splits its value on (`','` by default), or
     * `null` for a single-value filter. Resolved from the filter's public
     * `delimiter` property — present only on the set-valued filters
     * ({@see \haddowg\JsonApi\Resource\Filter\WhereIn} et al.) — so a single-value
     * filter never gets its scalar split.
     */
    private function delimiterFor(FilterInterface $filter): ?string
    {
        if (!\property_exists($filter, 'delimiter')) {
            return null;
        }

        /** @var mixed $delimiter */
        $delimiter = $filter->delimiter;

        return \is_string($delimiter) ? $delimiter : '';
    }
}
