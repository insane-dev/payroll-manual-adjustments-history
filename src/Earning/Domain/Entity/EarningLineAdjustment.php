<?php

declare(strict_types=1);

namespace App\Earning\Domain\Entity;

use App\Earning\Domain\Value\AdjustmentAuthorId;
use App\Earning\Domain\Value\EarningLineAdjustmentId;
use App\Earning\Domain\Value\EarningLineAdjustmentType;
use Carbon\CarbonImmutable;
use DomainException;
use Money\Money;

final readonly class EarningLineAdjustment
{
    public CarbonImmutable $recordedAt;

    public function __construct(
        public EarningLineAdjustmentId $id,
        public Money $amount,
        public ?string $comment,
        public ?AdjustmentAuthorId $authorId,
        CarbonImmutable $recordedAt,
        public EarningLineAdjustmentType $type = EarningLineAdjustmentType::MANUAL,
    ) {
        if (!$type->isInitial() && $amount->isZero()) {
            throw new DomainException('An Adjustment Amount must be nonzero.');
        }

        if ($type->isManual() && ($comment === null || preg_match('/\\S/u', $comment) !== 1)) {
            throw new DomainException('An Adjustment Comment must contain non-whitespace text.');
        }

        if ($type->isManual() && $authorId === null) {
            throw new DomainException('A Manual Adjustment requires an author.');
        }

        $this->recordedAt = $recordedAt->utc();
    }
}
