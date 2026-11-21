<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NestedFieldsets;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\NestedFieldset;
use haddowg\JsonApi\Serializer\SerializesNestedFieldsetsInterface;

/**
 * Renders a panel as an `easels` resource and receives the keys a request selected,
 * recording them, while still returning the whole value so core's trim shows.
 */
final class EaselSerializer extends BoardSerializer implements SerializesNestedFieldsetsInterface
{
    /**
     * The paths each call received, in call order.
     *
     * @var list<array{string, list<string>}>
     */
    public static array $selections = [];

    public function getType(mixed $object): string
    {
        return 'easels';
    }

    public function getNestedFieldsetAttribute(mixed $object, JsonApiRequestInterface $request, string $name, NestedFieldset $fieldset): mixed
    {
        self::$selections[] = [$name, $fieldset->paths()];
        $attribute = $this->getAttributes($object, $request)[$name] ?? null;

        return $attribute === null ? null : $attribute($object, $request, $name);
    }
}
