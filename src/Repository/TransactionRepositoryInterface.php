<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use App\Enum\TransactionStatus;

interface TransactionRepositoryInterface
{
    public function transactional(callable $operation): mixed;

    public function transition(Transaction $transaction, TransactionStatus $status): bool;

    public function findById(int $id): ?Transaction;

    /** @return Transaction[] */
    public function findByWalletId(int $walletId): array;

    /** @return Transaction[] */
    public function findByStatus(TransactionStatus $status): array;

    public function save(Transaction $transaction): void;
}
