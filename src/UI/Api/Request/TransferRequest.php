<?php

declare(strict_types=1);

namespace App\UI\Api\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class TransferRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'from_account_id is required.')]
        #[Assert\Uuid(message: 'from_account_id must be a valid UUID.')]
        public readonly string $fromAccountId = '',

        #[Assert\NotBlank(message: 'to_account_id is required.')]
        #[Assert\Uuid(message: 'to_account_id must be a valid UUID.')]
        public readonly string $toAccountId = '',

        #[Assert\NotBlank(message: 'amount is required.')]
        #[Assert\Regex(
            pattern: '/^\d+(\.\d{1,2})?$/',
            message: 'amount must be a positive number with up to 2 decimal places (e.g. "100.00").',
        )]
        #[Assert\Positive(message: 'amount must be greater than zero.')]
        public readonly string $amount = '',

        #[Assert\NotBlank(message: 'currency is required.')]
        #[Assert\Length(exactly: 3, exactMessage: 'currency must be a 3-letter ISO 4217 code (e.g. "USD").')]
        #[Assert\Choice(
            choices: ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD'],
            message: 'currency must be one of: USD, EUR, GBP, JPY, CAD, AUD.',
        )]
        public readonly string $currency = '',

        #[Assert\Length(max: 500, maxMessage: 'description cannot exceed 500 characters.')]
        public readonly ?string $description = null,
    ) {
    }
}
