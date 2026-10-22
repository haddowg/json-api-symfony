<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The request document decoded with JSON objects kept as objects, for JSON Schema
 * value validation. The parsed body is an associative decode, which turns `{}` into
 * `[]`, and opis then fails a valid empty object against `type: object`. Hydration
 * and the Symfony constraints keep using the associative parsed body.
 *
 * @internal
 */
final class ObjectView
{
    /**
     * The request attribute holding a sub-request's object-decoded `data` member, set
     * when the raw body is not the sub-request's own document (an atomic operation's
     * raw body is the whole batch). `null` means no object view is available.
     */
    public const DATA_ATTRIBUTE = '_jsonapi_object_data';

    /**
     * The object-decoded `data` member of the request document, or `null` when the
     * body is absent or not well-formed JSON.
     */
    public static function dataOf(ServerRequestInterface $request): mixed
    {
        $attributes = $request->getAttributes();
        if (\array_key_exists(self::DATA_ATTRIBUTE, $attributes)) {
            return $attributes[self::DATA_ATTRIBUTE];
        }

        $document = self::decode((string) $request->getBody());

        return $document instanceof \stdClass ? ($document->data ?? null) : null;
    }

    /**
     * Decodes JSON with objects kept as `\stdClass`, or `null` when it is empty or not
     * well-formed.
     */
    public static function decode(string $json): mixed
    {
        if ($json === '') {
            return null;
        }

        try {
            return \json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * The value as an associative decode of the same JSON holds it: every `\stdClass`
     * becomes an array.
     */
    public static function toArray(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        return \is_array($value) ? \array_map(self::toArray(...), $value) : $value;
    }
}
