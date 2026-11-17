<?php

declare(strict_types=1);

use SupportSla\Domain\Sla\SlaEngine;
use SupportSla\Support\Database;
use SupportSla\Support\Env;

require_once __DIR__ . '/../app/Support/Env.php';
require_once __DIR__ . '/../app/Support/Database.php';
require_once __DIR__ . '/../app/Domain/Sla/SlaEngine.php';

$root = dirname(__DIR__);
Env::load($root . '/.env');
date_default_timezone_set('UTC');
$db = Database::connection();
$engine = new SlaEngine();
$statement = $db->query("SELECT t.*, p.first_response_minutes, p.resolution_minutes, p.pause_statuses, c.type AS calendar_type, c.timezone, c.work_start, c.work_end, c.weekdays FROM tickets t INNER JOIN sla_policies p ON p.id = t.sla_policy_id INNER JOIN sla_calendars c ON c.id = p.calendar_id WHERE t.status NOT IN ('resolved', 'closed', 'cancelled')");
$tickets = $statement->fetchAll();
$updated = 0;

foreach ($tickets as $ticket) {
    $calendar = [
        'type' => $ticket['calendar_type'], 'timezone' => $ticket['timezone'], 'work_start' => $ticket['work_start'], 'work_end' => $ticket['work_end'],
        'weekdays' => json_decode((string) $ticket['weekdays'], true) ?: [1, 2, 3, 4, 5],
    ];
    $pauses = json_decode((string) $ticket['pause_statuses'], true) ?: [];
    $paused = in_array($ticket['status'], $pauses, true);
    $holidaysStatement = $db->prepare('SELECT holiday_date FROM sla_holidays WHERE calendar_id = (SELECT calendar_id FROM sla_policies WHERE id = ?)');
    $holidaysStatement->execute([$ticket['sla_policy_id']]);
    $holidays = array_column($holidaysStatement->fetchAll(), 'holiday_date');
    $started = new DateTimeImmutable((string) $ticket['created_at'], new DateTimeZone('UTC'));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $elapsed = $engine->elapsedWorkingMinutes($started, $now, $calendar, $holidays);
    $resolutionStatus = $paused ? 'paused' : $engine->status($now, new DateTimeImmutable((string) $ticket['resolution_due_at'], new DateTimeZone('UTC')), $elapsed, (int) $ticket['resolution_minutes'], false);
    $firstStatus = $ticket['first_response_at'] ? 'on_track' : ($paused ? 'paused' : $engine->status($now, new DateTimeImmutable((string) $ticket['first_response_due_at'], new DateTimeZone('UTC')), $elapsed, (int) $ticket['first_response_minutes'], false));
    if ($ticket['resolution_status'] !== $resolutionStatus || $ticket['first_response_status'] !== $firstStatus || (int) $ticket['resolution_elapsed_minutes'] !== $elapsed) {
        $update = $db->prepare('UPDATE tickets SET resolution_status = ?, first_response_status = ?, resolution_elapsed_minutes = ?, first_response_elapsed_minutes = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
        $update->execute([$resolutionStatus, $firstStatus, $elapsed, $elapsed, $ticket['id']]);
        if (in_array($resolutionStatus, ['at_risk', 'violated'], true) && $resolutionStatus !== $ticket['resolution_status']) {
            $exists = $db->prepare("SELECT id FROM notifications WHERE type = ? AND JSON_EXTRACT(data, '$.ticket_id') = ? AND created_at >= UTC_DATE() LIMIT 1");
            $exists->execute(['sla_' . $resolutionStatus, (int) $ticket['id']]);
            if (!$exists->fetchColumn() && $ticket['assignee_id']) {
                $notification = $db->prepare('INSERT INTO notifications (user_id, type, title, body, data, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())');
                $notification->execute([(int) $ticket['assignee_id'], 'sla_' . $resolutionStatus, $resolutionStatus === 'violated' ? 'SLA violado' : 'Chamado próximo do SLA', $ticket['number'] . ' precisa de atenção.', json_encode(['ticket_id' => (int) $ticket['id']])]);
            }
        }
        $updated++;
    }
}

echo "SLA worker: {$updated} chamados atualizados de " . count($tickets) . "." . PHP_EOL;
