<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through;

enum ThrTemplateGroup: string
{
    case Onboarding = 'onboarding';
    case Feedback = 'feedback';
    case Retired = 'retired';
}
