<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SupportSla\Domain\Sla\SlaEngine;

final class SlaEngineTest extends TestCase
{
    private function businessCalendar(): array
    {
        return ['type' => 'business', 'timezone' => 'UTC', 'work_start' => '08:00:00', 'work_end' => '18:00:00', 'weekdays' => [1, 2, 3, 4, 5]];
    }

    public function testAddsMinutesOnlyInsideBusinessHours(): void
    {
        $engine = new SlaEngine();
        $start = new DateTimeImmutable('2026-11-16 16:00:00', new DateTimeZone('UTC'));
        $due = $engine->calculateDueAt($start, 240, $this->businessCalendar());
        self::assertSame('2026-11-17 10:00:00', $due->format('Y-m-d H:i:s'));
    }

    public function testSkipsWeekend(): void
    {
        $engine = new SlaEngine();
        $start = new DateTimeImmutable('2026-11-20 17:00:00', new DateTimeZone('UTC'));
        $due = $engine->calculateDueAt($start, 120, $this->businessCalendar());
        self::assertSame('2026-11-23 09:00:00', $due->format('Y-m-d H:i:s'));
    }

    public function testSkipsHoliday(): void
    {
        $engine = new SlaEngine();
        $start = new DateTimeImmutable('2026-12-24 16:00:00', new DateTimeZone('UTC'));
        $due = $engine->calculateDueAt($start, 240, $this->businessCalendar(), ['2026-12-25']);
        self::assertSame('2026-12-28 10:00:00', $due->format('Y-m-d H:i:s'));
    }
}
