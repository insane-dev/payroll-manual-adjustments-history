<?php

declare(strict_types=1);

namespace App\Earning\Infrastructure\Mapper;

use App\Earning\Domain\Entity\EarningLine;
use App\Earning\Domain\Entity\EarningLineAdjustment;
use App\Earning\Domain\Value\AdjustmentAuthorId;
use App\Earning\Domain\Value\EarningLineAdjustmentId;
use App\Earning\Domain\Value\EarningLineAdjustmentType;
use App\Earning\Domain\Value\EarningLineId;
use Carbon\CarbonImmutable;
use LogicException;
use Money\Currency;
use Money\Money;
use OverflowException;
use Ramsey\Uuid\Uuid;
use UnexpectedValueException;

final class EarningLineMapper
{
    public function binaryId(string $id): string
    {
        return Uuid::fromString($id)->getBytes();
    }

    /** @return array<string, int|string> */
    public function toStateRow(EarningLine $line): array
    {
        return [
            'id' => $this->binaryId($line->id()->value),
            'initial_amount' => $this->signedInteger($line->initialAmount()->getAmount()),
            'system_amount' => $this->signedInteger($line->systemAmount()->getAmount()),
            'current_amount' => $this->signedInteger($line->currentAmount()->getAmount()),
            'currency' => $line->currentAmount()->getCurrency()->getCode(),
            'manually_adjusted' => (int) $line->isManuallyAdjusted(),
            'version' => $line->version(),
            'created_at' => $this->timestamp($line->createdAt()),
        ];
    }

    /** @return array<string, int|string|null> */
    public function toAdjustmentRow(EarningLineId $lineId, int $sequence, EarningLineAdjustment $adjustment): array
    {
        return [
            'line_id' => $this->binaryId($lineId->value),
            'id' => $this->binaryId($adjustment->id->value),
            'sequence' => $sequence,
            'type' => $adjustment->type->value,
            'amount' => $this->signedInteger($adjustment->amount->getAmount()),
            'comment' => $adjustment->comment,
            'author_id' => $adjustment->authorId === null ? null : $this->binaryId($adjustment->authorId->value),
            'recorded_at' => $this->timestamp($adjustment->recordedAt),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param list<array<string, mixed>> $rows
     */
    public function restore(array $state, array $rows): EarningLine
    {
        $currency = new Currency($state['currency']);
        $adjustments = array_map(fn(array $row): EarningLineAdjustment => new EarningLineAdjustment(
            new EarningLineAdjustmentId(Uuid::fromBytes($row['id'])->toString()),
            new Money($row['amount'], $currency),
            $row['comment'],
            $row['author_id'] === null ? null : new AdjustmentAuthorId(Uuid::fromBytes($row['author_id'])->toString()),
            $this->restoreTimestamp($row['recorded_at']),
            EarningLineAdjustmentType::from($row['type']),
        ), $rows);

        return EarningLine::restore(
            new EarningLineId(Uuid::fromBytes($state['id'])->toString()),
            new Money($state['initial_amount'], $currency),
            new Money($state['system_amount'], $currency),
            new Money($state['current_amount'], $currency),
            (bool) $state['manually_adjusted'],
            $state['version'],
            $this->restoreTimestamp($state['created_at']),
            $adjustments,
        );
    }

    private function signedInteger(string $value): int
    {
        if (PHP_INT_SIZE !== 8) {
            throw new LogicException('MySQL integer mapping requires 64-bit PHP.');
        }
        if (!is_numeric($value)) {
            throw new UnexpectedValueException('Expected a numeric storage value.');
        }
        if (bccomp($value, '-9223372036854775808', 0) < 0 || bccomp($value, '9223372036854775807', 0) > 0) {
            throw new OverflowException('Value exceeds the signed 64-bit storage range: ' . $value);
        }

        return (int) $value;
    }

    private function timestamp(CarbonImmutable $time): int
    {
        // Keep microseconds exact, including timestamps before the Unix epoch.
        return $this->signedInteger(bcadd(bcmul($time->format('U'), '1000000', 0), $time->format('u'), 0));
    }

    private function restoreTimestamp(int $timestamp): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC(intdiv($timestamp, 1000000))
            ->addMicroseconds($timestamp % 1000000);
    }
}
