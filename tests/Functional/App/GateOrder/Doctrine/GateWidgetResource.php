<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\GateOrder\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\GateOrder\BaseGateWidgetResource;
use haddowg\JsonApiBundle\Tests\Functional\App\GateOrder\GateSecurity;
use haddowg\JsonApiBundle\Tests\Functional\App\GateOrder\GateWidget;

#[AsJsonApiResource(
    entity: GateWidget::class,
    securityRead: GateSecurity::READ,
    securityList: GateSecurity::LIST,
    securityCreate: GateSecurity::ADMIN,
    securityUpdate: GateSecurity::UPDATE,
    securityDelete: GateSecurity::ADMIN,
)]
final class GateWidgetResource extends BaseGateWidgetResource {}
