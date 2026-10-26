<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Action;

use haddowg\JsonApi\OpenApi\Metadata\AcceptedBody;
use haddowg\JsonApi\OpenApi\Metadata\DescribesRawBody;
use haddowg\JsonApi\Request\MediaType;

/**
 * Normalises an action's `accepts` declaration into the scalar entries the compiled
 * action descriptor carries: one per accepted request media type, with the input mode
 * a body of that type is read in and the schema the OpenAPI document gives it.
 *
 * An entry is declared one of three ways:
 *  - a media type at an integer key, read in the action's own input mode;
 *  - a media type key mapped to an {@see ActionInput}, read in that mode;
 *  - a core {@see AcceptedBody} at an integer key, which also carries the body's
 *    schema; a JSON:API document body is read as a `Document`, any other in the
 *    action's mode, or as `Raw` for a `Document` action.
 *
 * Wildcards such as `image/*` are allowed. The three media types a browser sends
 * cross-origin without a preflight are accepted only when named outright.
 *
 * @phpstan-type AcceptedEntry array{mediaType: string, input: string, schema: array<string, mixed>|string|null, binary: bool}
 */
final class AcceptedMediaTypes
{
    /**
     * @param array<int|string, mixed> $accepts
     *
     * @return list<AcceptedEntry>
     */
    public static function normalise(array $accepts, ActionInput $input, string $action): array
    {
        $entries = [];
        foreach ($accepts as $key => $value) {
            if (\is_string($key)) {
                if (!$value instanceof ActionInput || $value === ActionInput::None) {
                    throw new \LogicException(\sprintf('The accepted media type "%s" of action "%s" must map to ActionInput::Document or ActionInput::Raw.', $key, $action));
                }
                $entry = ['mediaType' => self::mediaType($key, $action), 'input' => $value->name, 'schema' => null, 'binary' => false];
            } elseif (\is_string($value)) {
                $entry = ['mediaType' => self::mediaType($value, $action), 'input' => $input->name, 'schema' => null, 'binary' => false];
            } elseif ($value instanceof AcceptedBody) {
                $entry = [
                    'mediaType' => self::mediaType($value->mediaType, $action),
                    'input' => match (true) {
                        $value->document => ActionInput::Document->name,
                        $input === ActionInput::Document => ActionInput::Raw->name,
                        default => $input->name,
                    },
                    'schema' => $value->schema,
                    'binary' => $value->binary,
                ];
            } else {
                throw new \LogicException(\sprintf('Action "%s" declares an accepted media type that is neither a string nor an AcceptedBody.', $action));
            }

            foreach ($entries as $existing) {
                if ($existing['mediaType'] === $entry['mediaType']) {
                    throw new \LogicException(\sprintf('Action "%s" accepts the media type "%s" twice.', $action, $entry['mediaType']));
                }
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * The OpenAPI accepted bodies for normalised entries.
     *
     * @param list<AcceptedEntry> $entries
     *
     * @return list<AcceptedBody>
     */
    public static function bodies(array $entries): array
    {
        $bodies = [];
        foreach ($entries as $entry) {
            $document = $entry['input'] === ActionInput::Document->name;
            $schema = $entry['schema'];
            if ($document || (\is_string($schema) && !\is_a($schema, DescribesRawBody::class, true))) {
                $schema = null;
            }
            $bodies[] = new AcceptedBody($entry['mediaType'], $schema, !$document && $entry['binary'], document: $document);
        }

        return $bodies;
    }

    private static function mediaType(string $mediaType, string $action): string
    {
        $essence = MediaType::essence($mediaType);
        if ($essence !== '*/*' && \preg_match('#^[a-z0-9!\#$&^_.+-]+/([a-z0-9!\#$&^_.+-]+|\*)$#', $essence) !== 1) {
            throw new \LogicException(\sprintf('Action "%s" accepts "%s", which is not a media type such as "text/csv" or "image/*".', $action, $mediaType));
        }

        return $essence;
    }
}
