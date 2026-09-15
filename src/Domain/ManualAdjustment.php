<?php

declare(strict_types=1);

namespace Alcor\Payroll\Domain;

use Carbon\CarbonImmutable;
use DomainException;
use Money\Money;

final readonly class ManualAdjustment
{
    public CarbonImmutable $recordedAt;

    public function __construct(
        public ManualAdjustmentId $id,
        public Money $amount,
        public string $comment,
        public AdjustmentAuthorId $authorId,
        CarbonImmutable $recordedAt,
    ) {
        if ($amount->isZero()) {
            throw new DomainException('An Adjustment Amount must be nonzero.');
        }

        if (preg_match('/\\S/u', $comment) !== 1) {
            throw new DomainException('An Adjustment Comment must contain non-whitespace text.');
        }

        $this->recordedAt = $recordedAt->utc();
    }
}
