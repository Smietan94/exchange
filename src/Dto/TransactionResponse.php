<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Transaction;
use App\Service\MoneyAmount;
use DateTimeInterface;
use JsonSerializable;

final readonly class TransactionResponse implements JsonSerializable
{
    public function __construct(private Transaction $transaction)
    {
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->transaction->getId(),
            'fromWalletId' => $this->transaction->getFromWalletId(),
            'toWalletId' => $this->transaction->getToWalletId(),
            'fromAmount' => MoneyAmount::format($this->transaction->getFromAmount()),
            'toAmount' => MoneyAmount::format($this->transaction->getToAmount()),
            'fromCurrency' => $this->transaction->getFromCurrency()->value,
            'toCurrency' => $this->transaction->getToCurrency()->value,
            'spread' => MoneyAmount::format($this->transaction->getSpread()),
            'exchangeRate' => $this->transaction->getExchangeRate(),
            'status' => $this->transaction->getStatus()->value,
            'createdAt' => $this->transaction->getCreatedAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
