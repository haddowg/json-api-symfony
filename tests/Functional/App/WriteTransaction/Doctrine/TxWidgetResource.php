<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\BaseTxWidgetResource;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxWidget;

#[AsJsonApiResource(entity: TxWidget::class)]
final class TxWidgetResource extends BaseTxWidgetResource {}
