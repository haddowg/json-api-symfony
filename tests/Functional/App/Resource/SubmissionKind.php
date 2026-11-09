<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

enum SubmissionKind: string
{
    case Draft = 'draft';
    case Final = 'final';
}
