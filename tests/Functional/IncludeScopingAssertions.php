<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

/**
 * Request and document helpers for the include-scoping suites.
 */
trait IncludeScopingAssertions
{
    /**
     * @param array<string, string> $server
     *
     * @return array<string, mixed>
     */
    protected function get(string $path, array $server = []): array
    {
        $response = $this->handle($path, extraServer: $server);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * Neither the soft-hidden agency nor the hidden member appears anywhere.
     *
     * @param array<string, mixed> $document
     */
    protected function assertNoHiddenRow(array $document): void
    {
        $json = (string) \json_encode($document);
        self::assertStringNotContainsString('Soft Hidden', $json);
        self::assertStringNotContainsString('Hidden Member', $json);
        self::assertStringNotContainsString('Secret Role', $json);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function resource(array $document, string $id): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $resources = \array_is_list($data) ? $data : [$data];
        foreach ($resources as $resource) {
            if (\is_array($resource) && ($resource['id'] ?? null) === $id) {
                /** @var array<string, mixed> $resource */
                return $resource;
            }
        }

        self::fail(\sprintf('No primary resource "%s".', $id));
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function relationship(array $document, string $id, string $name): array
    {
        $relationship = $this->value($this->resource($document, $id), 'relationships', $name);
        self::assertIsArray($relationship, \sprintf('resource %s has no "%s" relationship', $id, $name));

        /** @var array<string, mixed> $relationship */
        return $relationship;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    protected function linkageIds(array $document, string $id, string $name): array
    {
        $relationship = $this->relationship($document, $id, $name);
        self::assertArrayHasKey('data', $relationship, \sprintf('resource %s "%s" renders no linkage data', $id, $name));

        return $this->ids($relationship['data']);
    }

    /**
     * @param array<string, mixed> $document
     */
    protected function toOneId(array $document, string $id, string $name): ?string
    {
        $id = $this->value($this->relationship($document, $id, $name), 'data', 'id');

        return \is_string($id) ? $id : null;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    protected function includedIds(array $document, string $type): array
    {
        $ids = [];
        foreach ($this->included($document) as $resource) {
            if (($resource['type'] ?? null) === $type && \is_string($resource['id'] ?? null)) {
                $ids[] = $resource['id'];
            }
        }
        \sort($ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    protected function includedLinkageIds(array $document, string $type, string $id, string $name): array
    {
        foreach ($this->included($document) as $resource) {
            if (($resource['type'] ?? null) === $type && ($resource['id'] ?? null) === $id) {
                return $this->ids($this->value($resource, 'relationships', $name, 'data'));
            }
        }

        self::fail(\sprintf('No included %s "%s".', $type, $id));
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array<mixed>>
     */
    protected function included(array $document): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        return \array_values(\array_filter($included, '\is_array'));
    }

    /**
     * @return list<string>
     */
    protected function ids(mixed $identifiers): array
    {
        self::assertIsArray($identifiers);
        $ids = [];
        foreach ($identifiers as $identifier) {
            if (\is_array($identifier) && \is_string($identifier['id'] ?? null)) {
                $ids[] = $identifier['id'];
            }
        }
        \sort($ids);

        return $ids;
    }

    /**
     * @param array<mixed> $array
     */
    protected function value(array $array, string ...$path): mixed
    {
        $value = $array;
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
