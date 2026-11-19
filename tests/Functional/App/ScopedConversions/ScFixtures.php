<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ScopedConversions;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Journals 1 and 2 in memory.
 */
final class ScFixtures
{
    private ?InMemoryDataProvider $provider = null;

    /**
     * @return list<ScJournal>
     */
    public static function journals(): array
    {
        return [new ScJournal('1', 'Main'), new ScJournal('2', 'Side')];
    }

    public function provider(): InMemoryDataProvider
    {
        if ($this->provider === null) {
            $byId = [];
            foreach (self::journals() as $journal) {
                $byId[(string) $journal->id] = $journal;
            }
            $this->provider = new InMemoryDataProvider('sc-journals', $byId, static fn(object $journal): string => $journal instanceof ScJournal ? (string) $journal->id : '');
        }

        return $this->provider;
    }

    public function persister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister('sc-journals', $this->provider()->store(), static fn(): object => new ScJournal());
    }
}
