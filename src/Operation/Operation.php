<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Operation;

/**
 * The five CRUD operations a JSON:API type can expose. The public DX type an
 * application lists on `#[AsJsonApiResource(operations:)]` /
 * `#[AsJsonApiSerializer(operations:)]` to declare exactly which endpoints the
 * type serves; the route loader emits exactly one route per declared case (bundle
 * ADR 0025).
 *
 * Each case value equals its name, so a descriptor can flow through the container
 * as a plain case-value string (objects are not container-dumpable). Each case
 * maps to one route, with `$seg` the resource's `$uriType`:
 *  - {@see self::FetchCollection} → `GET /{seg}`
 *  - {@see self::FetchOne}        → `GET /{seg}/{id}`
 *  - {@see self::Create}          → `POST /{seg}`
 *  - {@see self::Update}          → `PATCH /{seg}/{id}`
 *  - {@see self::Delete}          → `DELETE /{seg}/{id}`
 *
 * Relationship endpoints are a separate slice and are not gated by this enum.
 */
enum Operation: string
{
    case FetchCollection = 'FetchCollection';
    case FetchOne = 'FetchOne';
    case Create = 'Create';
    case Update = 'Update';
    case Delete = 'Delete';
}
