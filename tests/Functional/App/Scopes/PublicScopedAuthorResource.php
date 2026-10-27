<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Scopes;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseAuthorResource;

/**
 * An author whose single read is public yet given a scope, which fails the build.
 */
#[AsJsonApiResource(securityRead: false, operationScopes: ['fetchOne' => ['authors:read']])]
final class PublicScopedAuthorResource extends BaseAuthorResource {}
