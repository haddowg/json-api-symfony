<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

use haddowg\JsonApi\OpenApi\Serve\EntityTag;
use Symfony\Component\Yaml\Yaml;

/**
 * The encodings the {@see \haddowg\JsonApiBundle\Controller\OpenApiController} serves a
 * document in, picked by the `format` route default (`json` when a route sets none).
 *
 * Every format is encoded from the JSON body, so the YAML document is the JSON one in
 * another syntax: served, it has the same decorators, pruning and cache keys, and the
 * export command and the `public_path` file dump it the same way.
 */
enum DocumentFormat: string
{
    /** The route default a document route names its format in. */
    public const string ROUTE_DEFAULT = 'format';

    case Json = 'json';
    case Yaml = 'yaml';

    /**
     * Whether symfony/yaml is installed, which serving YAML needs.
     */
    public static function yamlAvailable(): bool
    {
        return \class_exists(Yaml::class);
    }

    /**
     * The format a route serves: its `format` default, else JSON. Null for a format the
     * controller doesn't serve.
     */
    public static function ofRoute(mixed $default): ?self
    {
        return $default === null ? self::Json : (\is_string($default) ? self::tryFrom($default) : null);
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Json => 'application/json',
            // RFC 9512.
            self::Yaml => 'application/yaml',
        };
    }

    /**
     * The ETag hash of this encoding of a document whose JSON body hashes to `$hash`. JSON
     * keeps the hash as it is, and any other format folds its name in, so two encodings of
     * one document never share a tag and a 304 never needs the encoded bytes.
     */
    public function etag(string $hash): string
    {
        return $this === self::Json ? $hash : EntityTag::hash($hash . "\n" . $this->value);
    }

    /**
     * Encodes a document from its JSON body.
     *
     * YAML is dumped from the JSON decoded to objects, so an empty object stays a map and
     * an empty array stays a sequence, and the dumper quotes any string that would read
     * back as a number, boolean or null.
     */
    public function encode(string $json): string
    {
        if ($this === self::Json) {
            return $json;
        }

        return Yaml::dump(
            \json_decode($json, false, 512, \JSON_THROW_ON_ERROR),
            16,
            2,
            Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE,
        );
    }
}
