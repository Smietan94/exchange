<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class WalletDeletionConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A wallet can only be deleted when its balance is zero and it has no pending transactions.');
    }
}
