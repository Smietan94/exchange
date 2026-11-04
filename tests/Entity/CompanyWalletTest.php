<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CompanyWallet;
use App\Enum\Currency;
use Brick\Money\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class CompanyWalletTest extends TestCase
{
    public function testCreateInitialValues(): void
    {
        $wallet = CompanyWallet::create(Currency::EUR);

        $this->assertNull($wallet->getId());
        $this->assertSame(Currency::EUR, $wallet->getCurrency());
        $this->assertTrue(Money::zero('EUR')->isEqualTo($wallet->getBalance()));
    }

    public function testCreateSetsTimestamps(): void
    {
        $before = new DateTimeImmutable();
        $wallet = CompanyWallet::create(Currency::PLN);
        $after = new DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $wallet->getCreatedAt());
        $this->assertLessThanOrEqual($after, $wallet->getCreatedAt());
        $this->assertGreaterThanOrEqual($before, $wallet->getUpdatedAt());
        $this->assertLessThanOrEqual($after, $wallet->getUpdatedAt());
    }

    public function testCreateSetsEqualCreatedAtAndUpdatedAt(): void
    {
        $wallet = CompanyWallet::create(Currency::USD);

        $this->assertSame($wallet->getCreatedAt(), $wallet->getUpdatedAt());
    }

    public function testSetBalance(): void
    {
        $wallet = CompanyWallet::create(Currency::PLN);
        $wallet->setBalance(Money::of('250.75', 'PLN'));

        $this->assertTrue(Money::of('250.75', 'PLN')->isEqualTo($wallet->getBalance()));
    }

    public function testSetBalanceToZero(): void
    {
        $wallet = CompanyWallet::create(Currency::PLN);
        $wallet->setBalance(Money::of('100.00', 'PLN'));
        $wallet->setBalance(Money::zero('PLN'));

        $this->assertTrue(Money::zero('PLN')->isEqualTo($wallet->getBalance()));
    }

    public function testGetCurrency(): void
    {
        foreach (Currency::cases() as $currency) {
            $wallet = CompanyWallet::create($currency);
            $this->assertSame($currency, $wallet->getCurrency());
        }
    }
}
