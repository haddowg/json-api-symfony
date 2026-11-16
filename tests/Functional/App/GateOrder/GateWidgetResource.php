<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\GateOrder;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(
    securityRead: GateSecurity::READ,
    securityList: GateSecurity::LIST,
    securityCreate: GateSecurity::ADMIN,
    securityUpdate: GateSecurity::UPDATE,
    securityDelete: GateSecurity::ADMIN,
)]
final class GateWidgetResource extends BaseGateWidgetResource {}
