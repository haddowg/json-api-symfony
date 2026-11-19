<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionInputContext;

/**
 * The input an action hydrates, starting from these values.
 */
final class ContextNote
{
    public ?string $id = null;

    public ?string $code = 'unset';

    public ?string $note = 'unset';
}
