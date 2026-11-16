<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\GateOrder;

/**
 * The `gateWidgets` security expressions both kernels declare.
 */
final class GateSecurity
{
    public const ADMIN = "is_granted('ROLE_ADMIN')";

    public const READ = "is_granted('ROLE_ADMIN') or (is_granted('ROLE_USER') and object.name != 'secret')";

    public const LIST = "is_granted('ROLE_USER') and not is_granted('ROLE_NO_LIST')";

    public const UPDATE = "is_granted('ROLE_ADMIN') or object.owner == user.getUserIdentifier()";
}
