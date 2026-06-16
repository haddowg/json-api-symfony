<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;
use haddowg\JsonApiBundle\DataProvider\Doctrine\QueryPurpose;

/**
 * A request-AWARE extension scoping `authors`: it always applies an unconditional
 * base scope (exclude `Ada Lovelace`, author 1), and — only when the
 * {@see ExtensionContext::$request} is present (the related/include/batch loads,
 * never the primary `GET /authors` collection) — reads a gating header off the
 * parsed JSON:API request and, when it says editors are hidden, applies a SECOND
 * exclusion (`Grace Hopper`, author 2). This is the witness for bundle ADR 0070:
 *
 *  - a primary `GET /authors` collection reports {@see QueryPurpose::FetchCollection}
 *    with a `null` request, so the header branch cannot fire (the base scope still
 *    holds — request-aware scoping falls through to the unconditional scope);
 *  - a related `GET /articles/{id}/editors` (or `?include=editors`) load reports
 *    {@see QueryPurpose::FetchRelatedCollection} WITH the request, so the header
 *    branch fires and the second exclusion lands on the related authors.
 *
 * It records each `(purpose, requestPresent)` pair so the test can assert which
 * path saw the request.
 */
final class RequestAwareAuthorsExtension implements DoctrineExtensionInterface
{
    public const string HIDE_EDITORS_HEADER = 'X-Hide-Editors';

    /**
     * @var list<array{purpose: QueryPurpose, requestPresent: bool}>
     */
    public array $seen = [];

    public function supports(string $type): bool
    {
        return $type === 'authors';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $this->seen[] = ['purpose' => $context->purpose, 'requestPresent' => $context->request !== null];

        $alias = $builder->getRootAliases()[0]
            ?? throw new \LogicException('The builder arrived without a root alias.');

        // The unconditional base scope — applied on every purpose, request or not.
        $builder
            ->andWhere(\sprintf('%s.name != :base_excluded_author', $alias))
            ->setParameter('base_excluded_author', 'Ada Lovelace');

        // Request-aware: a related/include/batch load carries the request, so read a
        // gating header off it and add a second exclusion when set. The primary
        // collection has a null request, so this branch is skipped there.
        if ($context->request !== null
            && $context->request->getHeaderLine(self::HIDE_EDITORS_HEADER) === 'true') {
            $builder
                ->andWhere(\sprintf('%s.name != :request_excluded_author', $alias))
                ->setParameter('request_excluded_author', 'Grace Hopper');
        }

        return $builder;
    }
}
