<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Wallet;
use App\Enum\Currency;
use Brick\Money\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class WalletTest extends TestCase
{
    public function testGetters(): void
    {
        $wallet = Wallet::create(userId: 5, currency: Currency::EUR);

        $this->assertNull($wallet->getId());
        $this->assertSame(5, $wallet->getUserId());
        $this->assertSame(Currency::EUR, $wallet->getCurrency());
        $this->assertTrue(Money::zero('EUR')->isEqualTo($wallet->getBalance()));
        $this->assertFalse($wallet->isBlocked());
        $this->assertNull($wallet->getLastActivityAt());
    }

    public function testSetBalance(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $wallet->setBalance(Money::of('150.50', 'PLN'));

        $this->assertTrue(Money::of('150.50', 'PLN')->isEqualTo($wallet->getBalance()));
    }

    public function testSetIsBlocked(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $wallet->setIsBlocked(true);

        $this->assertTrue($wallet->isBlocked());
    }

    public function testSetLastActivityAt(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $date = new DateTimeImmutable('2024-06-01 10:00:00');
        $wallet->setLastActivityAt($date);

        $this->assertSame($date, $wallet->getLastActivityAt());
    }

    public function testSetLastActivityAtWithNull(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $wallet->setLastActivityAt(new DateTimeImmutable('2024-06-01 10:00:00'));
        $wallet->setLastActivityAt(null);

        $this->assertNull($wallet->getLastActivityAt());
    }
}
