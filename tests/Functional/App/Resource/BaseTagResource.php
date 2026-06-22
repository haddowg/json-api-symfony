<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The genericity-witness `tags` declaration both functional kernels serve: a new
 * type added with **nothing but** this resource (+ a Doctrine entity / in-memory
 * POJO) — no per-type handler, route, serializer or persister code — yet the full
 * JSON:API endpoint set works on both providers. It proves the capstone claim
 * that the CRUD engine is the zero-handler default (ADR 0021).
 *
 * Minimal on purpose: an id, a required `name`, and one to-one `article`
 * relationship (to the existing `articles` type) so the relationship endpoints
 * are exercised for a fresh type too. The concrete subclasses only choose the
 * data layer ({@see TagResource} in-memory,
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineTagResource}
 * Doctrine-mapped).
 */
abstract class BaseTagResource extends AbstractResource
{
    public static string $type = 'tags';

    public function fields(): array
    {
        return [
            // Store-provided id: a database auto-increment assigns it (core ADR 0048).
            Id::make(),
            Str::make('name')->required(),
            BelongsTo::make('article', 'articles'),
        ];
    }
}
