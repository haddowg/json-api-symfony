<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphGate;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: GatedArticle::class, readOnly: true, securityRead: "object.name != 'restricted'")]
final class DoctrineGatedArticleResource extends GatedArticleResource {}
