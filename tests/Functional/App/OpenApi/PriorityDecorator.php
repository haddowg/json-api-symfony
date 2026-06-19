<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

use haddowg\JsonApi\OpenApi\Info;
use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApiBundle\OpenApi\OpenApiFactoryInterface;

/**
 * A test {@see OpenApiFactoryInterface} decorator that overwrites the document title
 * with a fixed label, so two instances registered at different priorities prove which
 * decorator gets the final word: the one applied LAST (the highest priority) wins,
 * because each overwrites the title outright.
 */
final readonly class PriorityDecorator implements OpenApiFactoryInterface
{
    public function __construct(private string $label) {}

    public function decorate(OpenApi $document, string $server): OpenApi
    {
        return $document->withInfo(new Info($this->label, $document->info->version));
    }
}
