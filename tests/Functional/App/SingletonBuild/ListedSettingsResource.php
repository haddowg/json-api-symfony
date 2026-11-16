<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SingletonBuild;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Operation\Operation;

#[AsJsonApiResource(operations: [Operation::FetchCollection, Operation::FetchOne, Operation::Delete])]
final class ListedSettingsResource extends SettingsResource {}
