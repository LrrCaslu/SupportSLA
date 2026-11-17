<?php

declare(strict_types=1);

namespace SupportSla\Domain\Sla;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class SlaEngine
{
    public function calculateDueAt(DateTimeImmutable $startedAtUtc, int $minutes, array $calendar, array $holidays = []): DateTimeImmutable
    {
        if ($minutes <= 0) {
            return $startedAtUtc;
        }

        $timezone = new DateTimeZone((string) ($calendar['timezone'] ?? 'America/Sao_Paulo'));
        $local = $startedAtUtc->setTimezone($timezone);
        $remaining = $minutes;
        $guard = 0;

        while ($remaining > 0 && $guard < 20000) {
            $guard++;
            if (!$this->isWorkingDay($local, $calendar, $holidays)) {
                $local = $this->startOfNextDay($local);
                continue;
            }

            if (($calendar['type'] ?? 'business') === '24x7') {
                $chunk = min($remaining, 24 * 60);
                $local = $local->add(new DateInterval('PT' . $chunk . 'M'));
                $remaining -= $chunk;
                continue;
            }

            $start = $this->timeOnDate($local, (string) ($calendar['work_start'] ?? '08:00:00'));
            $end = $this->timeOnDate($local, (string) ($calendar['work_end'] ?? '18:00:00'));

            if ($local < $start) {
                $local = $start;
            }
            if ($local >= $end) {
                $local = $this->startOfNextDay($local);
                continue;
            }

            $available = max(1, (int) floor(($end->getTimestamp() - $local->getTimestamp()) / 60));
            $chunk = min($remaining, $available);
            $local = $local->add(new DateInterval('PT' . $chunk . 'M'));
            $remaining -= $chunk;
            if ($remaining > 0) {
                $local = $this->startOfNextDay($local);
            }
        }

        return $local->setTimezone(new DateTimeZone('UTC'));
    }

    public function elapsedWorkingMinutes(DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc, array $calendar, array $holidays = []): int
    {
        if ($toUtc <= $fromUtc) {
            return 0;
        }

        $timezone = new DateTimeZone((string) ($calendar['timezone'] ?? 'America/Sao_Paulo'));
        $cursor = $fromUtc->setTimezone($timezone);
        $endUtc = $toUtc;
        $minutes = 0;
        $guard = 0;

        while ($cursor->setTimezone(new DateTimeZone('UTC')) < $endUtc && $guard < 20000) {
            $guard++;
            if (!$this->isWorkingDay($cursor, $calendar, $holidays)) {
                $cursor = $this->startOfNextDay($cursor);
                continue;
            }

            if (($calendar['type'] ?? 'business') === '24x7') {
                $next = $cursor->add(new DateInterval('PT1M'));
                if ($next->setTimezone(new DateTimeZone('UTC')) > $endUtc) {
                    break;
                }
                $minutes++;
                $cursor = $next;
                continue;
            }

            $start = $this->timeOnDate($cursor, (string) ($calendar['work_start'] ?? '08:00:00'));
            $end = $this->timeOnDate($cursor, (string) ($calendar['work_end'] ?? '18:00:00'));
            if ($cursor < $start) {
                $cursor = $start;
                continue;
            }
            if ($cursor >= $end) {
                $cursor = $this->startOfNextDay($cursor);
                continue;
            }

            $next = $cursor->add(new DateInterval('PT1M'));
            if ($next > $end) {
                $cursor = $end;
                continue;
            }
            if ($next->setTimezone(new DateTimeZone('UTC')) <= $endUtc) {
                $minutes++;
            }
            $cursor = $next;
        }

        return $minutes;
    }

    public function status(DateTimeImmutable $nowUtc, ?DateTimeImmutable $dueAtUtc, int $elapsedMinutes, int $targetMinutes, bool $paused = false): string
    {
        if (!$dueAtUtc || $elapsedMinutes >= $targetMinutes || $nowUtc >= $dueAtUtc) {
            return 'violated';
        }
        if ($paused) {
            return 'paused';
        }
        $ratio = $targetMinutes > 0 ? $elapsedMinutes / $targetMinutes : 1;
        return $ratio >= 0.9 ? 'at_risk' : 'on_track';
    }

    private function isWorkingDay(DateTimeImmutable $date, array $calendar, array $holidays): bool
    {
        if (($calendar['type'] ?? 'business') === '24x7') {
            return true;
        }

        $weekdays = $calendar['weekdays'] ?? [1, 2, 3, 4, 5];
        $weekday = (int) $date->format('N');
        if (!in_array($weekday, array_map('intval', (array) $weekdays), true)) {
            return false;
        }

        return !in_array($date->format('Y-m-d'), array_map('strval', $holidays), true);
    }

    private function timeOnDate(DateTimeImmutable $date, string $time): DateTimeImmutable
    {
        [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', $time)), 3, 0);
        return $date->setTime($hour, $minute, $second);
    }

    private function startOfNextDay(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->modify('+1 day')->setTime(0, 0, 0);
    }
}

