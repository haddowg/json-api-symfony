<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Action;

use haddowg\JsonApi\OpenApi\Header;
use haddowg\JsonApi\OpenApi\Metadata\DescribesRawBody;
use haddowg\JsonApi\OpenApi\Metadata\RawResult;

/**
 * Converts a core {@see RawResult} to the scalar array an action descriptor carries
 * through the compiled container, and back. A header declared as a {@see Header}
 * keeps its description and its required and deprecated flags; its schema is the
 * string one the projector gives a header that declares none.
 *
 * @internal
 */
final class RawResults
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(RawResult $result): array
    {
        $headers = [];
        foreach ($result->headers as $name => $header) {
            $headers[$name] = $header instanceof Header
                ? ['description' => $header->description, 'required' => $header->required, 'deprecated' => $header->deprecated]
                : ['description' => $header];
        }

        return [
            'mediaType' => $result->mediaType,
            'schema' => $result->schema,
            'binary' => $result->binary,
            'headers' => $headers,
            'example' => $result->example,
            'status' => $result->status,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): RawResult
    {
        $headers = [];
        foreach (\is_array($raw['headers'] ?? null) ? $raw['headers'] : [] as $name => $header) {
            if (!\is_string($name) || !\is_array($header)) {
                continue;
            }
            $description = \is_string($header['description'] ?? null) ? $header['description'] : null;
            $required = \is_bool($header['required'] ?? null) ? $header['required'] : null;
            $deprecated = \is_bool($header['deprecated'] ?? null) ? $header['deprecated'] : null;
            $headers[$name] = $required === null && $deprecated === null && $description !== null
                ? $description
                : new Header($description, $required, $deprecated);
        }

        $declared = $raw['schema'] ?? null;
        /** @var array<string, mixed>|null $schema */
        $schema = \is_array($declared) ? $declared : null;
        if (\is_string($declared) && \is_a($declared, DescribesRawBody::class, true)) {
            $schema = $declared;
        }

        return new RawResult(
            \is_string($raw['mediaType'] ?? null) ? $raw['mediaType'] : 'application/octet-stream',
            $schema,
            ($raw['binary'] ?? false) === true,
            $headers,
            $raw['example'] ?? null,
            \is_int($raw['status'] ?? null) ? $raw['status'] : 200,
        );
    }

    /**
     * The media types an action can answer in, in the order core negotiates them:
     * JSON:API when it declares any JSON:API response, then each raw result's type.
     *
     * @param list<array{kind: string, type?: string, jobType?: string, raw?: array<string, mixed>}> $responds
     *
     * @return list<string>
     */
    public static function mediaTypes(array $responds): array
    {
        $jsonApi = [];
        $raw = [];
        foreach ($responds as $entry) {
            if ($entry['kind'] === 'raw' && isset($entry['raw'])) {
                $raw[self::fromArray($entry['raw'])->essence()] = true;
            } else {
                $jsonApi = ['application/vnd.api+json'];
            }
        }

        return [...$jsonApi, ...\array_keys($raw)];
    }

    /**
     * Whether the action declares any raw result.
     *
     * @param list<array{kind: string, type?: string, jobType?: string, raw?: array<string, mixed>}> $responds
     */
    public static function any(array $responds): bool
    {
        foreach ($responds as $entry) {
            if ($entry['kind'] === 'raw') {
                return true;
            }
        }

        return false;
    }
}
