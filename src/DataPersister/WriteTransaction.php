<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

/**
 * Runs one write in a transaction on the persister that owns it: the CRUD handler wraps
 * every create, update, delete and relationship mutation in it, and the action invoker
 * every action called with POST, PATCH, PUT or DELETE.
 *
 * The transaction opens before the write's first step, so a flush a before hook makes
 * joins it, and anything that throws before the commit rolls all of it back. A
 * persister that can't transact runs the write as it is, and inside an Atomic
 * Operations batch the batch owns the transaction, so the write runs in it.
 */
final class WriteTransaction
{
    /**
     * @template T
     *
     * @param \Closure(): T $write
     *
     * @return T
     */
    public static function run(?DataPersisterInterface $persister, ?WriteTransactionContext $context, \Closure $write): mixed
    {
        if (!$persister instanceof TransactionalDataPersisterInterface || ($context?->isActive() ?? false)) {
            return $write();
        }

        $persister->beginTransaction();
        try {
            $result = $write();
            $persister->commit();
        } catch (\Throwable $throwable) {
            $persister->rollback();

            throw $throwable;
        }

        return $result;
    }
}
