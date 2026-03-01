<?php

declare(strict_types=1);

namespace App\Domain\Account;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Account aggregate root.
 *
 * Encapsulates balance logic. Balance changes only happen through
 * explicit domain methods, never by direct field mutation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'accounts')]
#[ORM\Index(columns: ['owner_id'], name: 'idx_accounts_owner')]
#[ORM\HasLifecycleCallbacks]
class Account
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 100)]
    private string $ownerId;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    /**
     * Balance stored as string to preserve decimal precision (via bcmath).
     */
    #[ORM\Column(type: 'string', length: 20)]
    private string $balance;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 0;

    public function __construct(
        string $id,
        string $ownerId,
        string $currency,
        string $initialBalance = '0.00',
    ) {
        if (empty($id)) {
            throw new InvalidArgumentException('Account ID cannot be empty.');
        }
        if (empty($ownerId)) {
            throw new InvalidArgumentException('Owner ID cannot be empty.');
        }

        $this->id = $id;
        $this->ownerId = $ownerId;
        $this->currency = strtoupper($currency);
        $this->balance = $initialBalance;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getBalance(): Money
    {
        return Money::of($this->balance, $this->currency);
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Debit funds from this account.
     *
     * @throws InsufficientFundsException
     * @throws AccountInactiveException
     */
    public function debit(Money $amount): void
    {
        $this->assertActive();
        $this->assertSameCurrency($amount);

        $currentBalance = $this->getBalance();

        if (!$currentBalance->isGreaterThanOrEqual($amount)) {
            throw InsufficientFundsException::forAccount($this->id, $currentBalance, $amount);
        }

        $this->balance = $currentBalance->subtract($amount)->getAmount();
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Credit funds to this account.
     *
     * @throws AccountInactiveException
     */
    public function credit(Money $amount): void
    {
        $this->assertActive();
        $this->assertSameCurrency($amount);

        $this->balance = $this->getBalance()->add($amount)->getAmount();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->updatedAt = new DateTimeImmutable();
    }

    private function assertActive(): void
    {
        if (!$this->active) {
            throw AccountInactiveException::forAccount($this->id);
        }
    }

    private function assertSameCurrency(Money $amount): void
    {
        if ($amount->getCurrency() !== $this->currency) {
            throw new InvalidArgumentException(
                sprintf('Account currency is %s but transfer currency is %s.', $this->currency, $amount->getCurrency())
            );
        }
    }
}
