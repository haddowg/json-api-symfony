<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

/**
 * A template setting, held by {@see FillSettingsProvider} rather than any data layer.
 */
final class FillSetting
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}
}
