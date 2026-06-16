<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Hook;

/**
 * A tiny in-memory audit trail the cross-cutting {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\EventListener\AuditLogSubscriber}
 * appends to whenever a write commits — the example's witness for an
 * **after-commit** event subscriber (the place a real app emits an audit record,
 * a domain event, a cache bust, a webhook).
 *
 * It is registered as a **public** service purely so the example test can read the
 * recorded entries back; a production app would inject a real logger or message
 * bus and never expose the store. Each entry is a `"{action} {type}#{id}"` line
 * (e.g. `created playlists#…`, `deleted tracks#4`).
 */
final class AuditLog
{
    /** @var list<string> */
    private array $entries = [];

    public function record(string $action, string $type, string $id): void
    {
        $this->entries[] = \sprintf('%s %s#%s', $action, $type, $id);
    }

    /**
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
