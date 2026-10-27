<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Scopes;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseCommentResource;

/**
 * A comment declaring scopes for a relation it does not have, which fails the build.
 */
#[AsJsonApiResource(operationScopes: ['fetchRelated.article' => ['comments:read']])]
final class UnknownRelationScopedCommentResource extends BaseCommentResource {}
