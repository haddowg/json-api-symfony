<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

/**
 * The request document of the `import` action: the secrets to import.
 */
final class FkImport
{
    public ?string $id = null;

    /**
     * @var iterable<mixed>
     */
    public iterable $secrets = [];
}
