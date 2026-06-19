<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Security;

/**
 * The in-memory `securedWidgets` model: the provider-agnostic twin of the Doctrine
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecuredWidgetEntity},
 * proving the authorization layer (bundle ADR 0043) gates identically over the
 * in-memory provider/persister.
 */
final class InMemorySecuredWidget
{
    public function __construct(
        public ?int $id = null,
        public string $name = '',
        public ?int $partnerId = null,
    ) {}
}
