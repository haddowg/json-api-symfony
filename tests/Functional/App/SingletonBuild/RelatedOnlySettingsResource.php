<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SingletonBuild;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(relatedOnly: true)]
final class RelatedOnlySettingsResource extends SettingsResource {}
