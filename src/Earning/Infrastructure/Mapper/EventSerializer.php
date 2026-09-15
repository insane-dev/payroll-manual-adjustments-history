<?php

declare(strict_types=1);

namespace App\Earning\Infrastructure\Mapper;

use App\Earning\Domain\Entity\ManualAdjustment;
use App\Earning\Domain\Event\EarningLineCreated;
use App\Earning\Domain\Event\EarningLineEvent;
use App\Earning\Domain\Event\ManualAdjustmentAdded;
use App\Earning\Domain\Event\SystemAmountUpdated;
use App\Earning\Domain\Value\AdjustmentAuthorId;
use App\Earning\Domain\Value\EarningLineId;
use App\Earning\Domain\Value\ManualAdjustmentId;
use Carbon\CarbonImmutable;
use Money\Currency;
use Money\Money;
use UnexpectedValueException;

final class EventSerializer
{
    private const string TIMESTAMP_FORMAT = 'Y-m-d\\TH:i:s.uP';

    /** @return array{type: string, payload: string} */
    public function encode(EarningLineEvent $event): array
    {
        [$type, $payload] = match (true) {
            $event instanceof EarningLineCreated => ['earning-line-created.v1', [
                'earning_line_id' => $event->earningLineId->value,
                'amount' => $event->systemAmount->getAmount(),
                'currency' => $event->systemAmount->getCurrency()->getCode(),
                'recorded_at' => $event->recordedAt->format(self::TIMESTAMP_FORMAT),
            ]],
            $event instanceof SystemAmountUpdated => ['system-amount-updated.v1', [
                'amount' => $event->systemAmount->getAmount(),
                'currency' => $event->systemAmount->getCurrency()->getCode(),
                'recorded_at' => $event->recordedAt->format(self::TIMESTAMP_FORMAT),
            ]],
            $event instanceof ManualAdjustmentAdded => ['manual-adjustment-added.v1', [
                'adjustment_id' => $event->adjustment->id->value,
                'amount' => $event->adjustment->amount->getAmount(),
                'currency' => $event->adjustment->amount->getCurrency()->getCode(),
                'comment' => $event->adjustment->comment,
                'author_id' => $event->adjustment->authorId->value,
                'recorded_at' => $event->adjustment->recordedAt->format(self::TIMESTAMP_FORMAT),
            ]],
            default => throw new UnexpectedValueException('Unsupported Earning Line event.'),
        };

        return ['type' => $type, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
    }

    public function decode(string $type, string $payload): EarningLineEvent
    {
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        return match ($type) {
            'earning-line-created.v1' => new EarningLineCreated(
                new EarningLineId($data['earning_line_id']),
                $this->money($data),
                CarbonImmutable::parse($data['recorded_at']),
            ),
            'system-amount-updated.v1' => new SystemAmountUpdated(
                $this->money($data),
                CarbonImmutable::parse($data['recorded_at']),
            ),
            'manual-adjustment-added.v1' => new ManualAdjustmentAdded(new ManualAdjustment(
                new ManualAdjustmentId($data['adjustment_id']),
                $this->money($data),
                $data['comment'],
                new AdjustmentAuthorId($data['author_id']),
                CarbonImmutable::parse($data['recorded_at']),
            )),
            default => throw new UnexpectedValueException('Unknown event type: ' . $type),
        };
    }

    /** @param array<string, string> $data */
    private function money(array $data): Money
    {
        return new Money($data['amount'], new Currency($data['currency']));
    }
}
