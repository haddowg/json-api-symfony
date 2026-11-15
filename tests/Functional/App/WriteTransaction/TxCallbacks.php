<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\DataPersister\AfterCommit;

/**
 * Registers the after-commit callbacks the hooks, actions and persister use: each logs its
 * label to {@see TxScenario::$log} when it runs, and the connection's nesting level at the
 * time on Doctrine.
 */
final readonly class TxCallbacks
{
    public function __construct(
        private AfterCommit $afterCommit,
        private ?EntityManagerInterface $entityManager = null,
    ) {}

    /**
     * Registers the logging callback, after one that throws when
     * {@see TxScenario::$throwingCallback} is set.
     */
    public function register(string $label): void
    {
        if (TxScenario::$throwingCallback) {
            $this->afterCommit->register(static function (): void {
                throw new \RuntimeException('The queue is down.');
            });
        }

        $this->afterCommit->register($this->callback($label));
    }

    /**
     * @return \Closure(): void
     */
    public function callback(string $label): \Closure
    {
        return function () use ($label): void {
            TxScenario::$log[] = $label;
            TxScenario::$callbackLevels[] = $this->entityManager?->getConnection()->getTransactionNestingLevel();
        };
    }
}
