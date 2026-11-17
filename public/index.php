<?php

declare(strict_types=1);

use SupportSla\Domain\Sla\SlaEngine;
use SupportSla\Support\Auth;
use SupportSla\Support\Database;
use SupportSla\Support\Env;
use SupportSla\Support\Response;

require_once __DIR__ . '/../app/Support/Env.php';
require_once __DIR__ . '/../app/Support/Database.php';
require_once __DIR__ . '/../app/Support/Response.php';
require_once __DIR__ . '/../app/Support/Auth.php';
require_once __DIR__ . '/../app/Domain/Sla/SlaEngine.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set('UTC');
session_name((string) Env::get('SESSION_NAME', 'support_sla_session'));
session_start();

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (!str_starts_with($requestPath, '/api')) {
    $index = __DIR__ . '/index.html';
    if (is_file($index)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($index);
        exit;
    }
    http_response_code(404);
    exit('Interface não encontrada.');
}

try {
    if (!($requestPath === '/api/login' && $method === 'POST')) {
        Auth::checkCsrf();
    }
    api($method, $requestPath);
} catch (Throwable $exception) {
    $debug = Env::bool('APP_DEBUG', false);
    Response::json([
        'message' => $debug ? $exception->getMessage() : 'Ocorreu um erro inesperado.',
        'type' => get_class($exception),
    ], 500);
}

function api(string $method, string $path): never
{
    $route = trim(substr($path, 4), '/');
    $segments = $route === '' ? [] : explode('/', $route);
    $resource = $segments[0] ?? '';
    $id = isset($segments[1]) && ctype_digit($segments[1]) ? (int) $segments[1] : null;
    $action = $segments[2] ?? null;

    if ($resource === 'login' && $method === 'POST') {
        $input = input();
        $email = (string) ($input['email'] ?? '');
        $password = (string) ($input['password'] ?? '');
        if ($email === '' || $password === '') {
            Response::error('Informe e-mail e senha.', 422);
        }
        $user = Auth::attempt($email, $password);
        if (!$user) {
            Response::error('E-mail, senha ou situação do usuário inválida.', 422);
        }
        $statement = Database::connection()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?');
        $statement->execute([$user['id']]);
        Response::json(['user' => $user]);
    }

    if ($resource === 'logout' && $method === 'POST') {
        Auth::logout();
        Response::json(['ok' => true]);
    }

    if ($resource === 'me' && $method === 'GET') {
        $user = Auth::user();
        if (!$user) {
            Response::json(['user' => null]);
        }
        Response::json(['user' => $user]);
    }

    $user = Auth::requireUser();

    if ($resource === 'dashboard' && $method === 'GET') {
        Response::json(dashboard($user));
    }
    if ($resource === 'lookups' && $method === 'GET') {
        Response::json(lookups($user));
    }
    if ($resource === 'notifications' && $method === 'GET') {
        Response::json(notifications($user));
    }
    if ($resource === 'notifications' && $method === 'POST' && $id === null && $action === 'read-all') {
        $statement = Database::connection()->prepare('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL');
        $statement->execute([$user['id']]);
        Response::json(['ok' => true]);
    }
    if ($resource === 'tickets' && $id === null && $method === 'GET') {
        Response::json(tickets($user));
    }
    if ($resource === 'tickets' && $id === null && $method === 'POST') {
        Response::json(createTicket($user), 201);
    }
    if ($resource === 'tickets' && $id !== null && $action === null && $method === 'GET') {
        Response::json(ticket($user, $id));
    }
    if ($resource === 'tickets' && $id !== null && $action === null && in_array($method, ['PATCH', 'PUT'], true)) {
        Response::json(updateTicket($user, $id));
    }
    if ($resource === 'tickets' && $id !== null && $action === 'messages' && $method === 'POST') {
        Response::json(addMessage($user, $id), 201);
    }
    if ($resource === 'tickets' && $id !== null && $action === 'attachments' && $method === 'POST') {
        Response::json(uploadAttachments($user, $id), 201);
    }
    if ($resource === 'attachments' && $id !== null && $action === 'download' && $method === 'GET') {
        downloadAttachment($user, $id);
    }
    if ($resource === 'knowledge' && $method === 'GET') {
        Response::json(knowledge($user));
    }
    if (in_array($resource, ['customers', 'contracts', 'teams', 'sla', 'assets', 'automations', 'reports'], true) && $id === null && $method === 'GET') {
        authorizeModule($user, $resource, 'view');
        Response::json(moduleData($user, $resource));
    }
    if (in_array($resource, ['customers', 'contracts', 'teams', 'sla', 'assets', 'automations'], true) && $id === null && $method === 'POST') {
        authorizeModule($user, $resource, 'create');
        Response::json(createModule($user, $resource), 201);
    }
    if ($resource === 'audit' && $method === 'GET') {
        authorizeModule($user, 'audit', 'view');
        Response::json(auditLogs($user));
    }

    Response::error('Rota não encontrada.', 404);
}

function input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function scope(array $user, string $alias = ''): array
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    if ($user['role'] === 'superadmin') {
        return ['1=1', []];
    }
    if ($user['role'] === 'customer') {
        return [$prefix . 'requester_id = ?', [(int) $user['id']]];
    }
    return [$prefix . 'organization_id = ?', [(int) $user['organization_id']]];
}

function dashboard(array $user): array
{
    $db = Database::connection();
    [$where, $params] = scope($user, 't');
    $count = static function (string $extra = '') use ($db, $where, $params): int {
        $statement = $db->prepare("SELECT COUNT(*) FROM tickets t WHERE {$where} {$extra}");
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    };
    $total = $count();
    $open = $count("AND t.status NOT IN ('resolved', 'closed', 'cancelled')");
    $inProgress = $count("AND t.status IN ('triage', 'in_progress')");
    $resolved = $count("AND t.status IN ('resolved', 'closed')");
    $breached = $count("AND (t.resolution_status = 'violated' OR t.first_response_status = 'violated')");
    $atRisk = $count("AND (t.resolution_status = 'at_risk' OR t.first_response_status = 'at_risk')");

    $group = static function (string $field) use ($db, $where, $params): array {
        $statement = $db->prepare("SELECT {$field} AS label, COUNT(*) AS value FROM tickets t WHERE {$where} GROUP BY {$field} ORDER BY value DESC");
        $statement->execute($params);
        return $statement->fetchAll();
    };
    $trendStatement = $db->prepare("SELECT DATE(t.created_at) AS label, COUNT(*) AS value FROM tickets t WHERE {$where} AND t.created_at >= UTC_DATE() - INTERVAL 6 DAY GROUP BY DATE(t.created_at) ORDER BY label");
    $trendStatement->execute($params);
    $trend = $trendStatement->fetchAll();

    $metricsStatement = $db->prepare("SELECT AVG(CASE WHEN first_response_at IS NOT NULL THEN first_response_elapsed_minutes END) AS first_response_avg, AVG(CASE WHEN resolved_at IS NOT NULL THEN resolution_elapsed_minutes END) AS resolution_avg, AVG(CASE WHEN resolution_status IN ('on_track', 'at_risk') THEN 100 ELSE 0 END) AS compliance FROM tickets t WHERE {$where}");
    $metricsStatement->execute($params);
    $metrics = $metricsStatement->fetch() ?: [];

    return [
        'cards' => [
            'total' => $total,
            'open' => $open,
            'in_progress' => $inProgress,
            'resolved' => $resolved,
            'breached' => $breached,
            'at_risk' => $atRisk,
            'compliance' => round((float) ($metrics['compliance'] ?? 0), 1),
            'first_response_avg' => round((float) ($metrics['first_response_avg'] ?? 0)),
            'resolution_avg' => round((float) ($metrics['resolution_avg'] ?? 0)),
        ],
        'charts' => [
            'status' => $group('t.status'),
            'priority' => $group('t.priority'),
            'trend' => $trend,
        ],
    ];
}

function lookups(array $user): array
{
    $db = Database::connection();
    $orgId = $user['organization_id'];
    if ($user['role'] === 'superadmin') {
        $orgId = (int) ($orgId ?: 1);
    }
    if ($user['role'] === 'customer') {
        $customer = $db->prepare('SELECT c.id, c.name, c.email FROM customers c INNER JOIN customer_contacts cc ON cc.customer_id = c.id WHERE cc.user_id = ? AND cc.authorized = 1 ORDER BY c.name');
        $customer->execute([$user['id']]);
        $services = $db->prepare('SELECT id, name, default_priority FROM services WHERE organization_id = ? AND active = 1 ORDER BY name');
        $services->execute([(int) $orgId]);
        return ['customers' => $customer->fetchAll(), 'services' => $services->fetchAll(), 'teams' => [], 'users' => [], 'calendars' => []];
    }
    $customer = $db->prepare('SELECT id, name, email FROM customers WHERE organization_id = ? AND status = \'active\' ORDER BY name');
    $customer->execute([$orgId]);
    $services = $db->prepare('SELECT id, name, default_priority FROM services WHERE organization_id = ? AND active = 1 ORDER BY name');
    $services->execute([$orgId]);
    $teams = $db->prepare('SELECT id, name FROM teams WHERE organization_id = ? AND active = 1 ORDER BY name');
    $teams->execute([$orgId]);
    $users = $db->prepare("SELECT id, name, role FROM users WHERE organization_id = ? AND active = 1 AND role IN ('technician', 'supervisor', 'manager') ORDER BY name");
    $users->execute([$orgId]);
    $calendars = $db->prepare('SELECT id, name, type, timezone FROM sla_calendars WHERE organization_id = ? OR organization_id IS NULL ORDER BY name');
    $calendars->execute([$orgId]);
    return ['customers' => $customer->fetchAll(), 'services' => $services->fetchAll(), 'teams' => $teams->fetchAll(), 'users' => $users->fetchAll(), 'calendars' => $calendars->fetchAll()];
}

function notifications(array $user): array
{
    $statement = Database::connection()->prepare('SELECT id, type, title, body, data, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 30');
    $statement->execute([$user['id']]);
    $rows = $statement->fetchAll();
    foreach ($rows as &$row) {
        $row['data'] = $row['data'] ? json_decode($row['data'], true) : null;
    }
    return $rows;
}

function tickets(array $user): array
{
    $db = Database::connection();
    [$where, $params] = scope($user, 't');
    $query = trim((string) ($_GET['search'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $priority = trim((string) ($_GET['priority'] ?? ''));
    if ($query !== '') {
        $where .= ' AND (t.number LIKE ? OR t.title LIKE ? OR c.name LIKE ?)';
        $like = '%' . $query . '%';
        array_push($params, $like, $like, $like);
    }
    if ($status !== '') {
        $where .= ' AND t.status = ?';
        $params[] = $status;
    }
    if ($priority !== '') {
        $where .= ' AND t.priority = ?';
        $params[] = $priority;
    }
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int) ($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;
    $countStatement = $db->prepare("SELECT COUNT(*) FROM tickets t LEFT JOIN customers c ON c.id = t.customer_id WHERE {$where}");
    $countStatement->execute($params);
    $total = (int) $countStatement->fetchColumn();
    $paramsWithPage = [...$params, $limit, $offset];
    $statement = $db->prepare("SELECT t.id, t.number, t.title, t.category, t.priority, t.status, t.resolution_status, t.first_response_status, t.created_at, t.resolution_due_at, c.name AS customer_name, u.name AS assignee_name, te.name AS team_name, (SELECT COUNT(*) FROM attachments a WHERE a.ticket_id = t.id) AS attachment_count FROM tickets t LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN users u ON u.id = t.assignee_id LEFT JOIN teams te ON te.id = t.team_id WHERE {$where} ORDER BY t.created_at DESC LIMIT ? OFFSET ?");
    foreach ($params as $index => $value) {
        $statement->bindValue($index + 1, $value);
    }
    $statement->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $statement->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $statement->execute();
    return ['data' => $statement->fetchAll(), 'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)]];
}

function createTicket(array $user): array
{
    if ($user['role'] === 'auditor') {
        Response::error('Seu perfil possui acesso somente para consulta.', 403);
    }
    $input = input();
    $title = trim((string) ($input['title'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    if ($title === '' || $description === '') {
        Response::error('Título e descrição são obrigatórios.', 422);
    }
    $db = Database::connection();
    $orgId = (int) ($user['organization_id'] ?? 0);
    $customerId = $input['customer_id'] ?? null;
    if ($user['role'] === 'customer') {
        $customerStatement = $db->prepare('SELECT customer_id FROM customer_contacts WHERE user_id = ? AND authorized = 1 LIMIT 1');
        $customerStatement->execute([$user['id']]);
        $customerId = $customerStatement->fetchColumn() ?: null;
    }
    if ($customerId !== null) {
        $check = $db->prepare('SELECT id FROM customers WHERE id = ? AND organization_id = ?');
        $check->execute([(int) $customerId, $orgId]);
        if (!$check->fetchColumn()) {
            Response::error('Cliente inválido para esta organização.', 422);
        }
    }
    $impact = (string) ($input['impact'] ?? 'medium');
    $urgency = (string) ($input['urgency'] ?? 'medium');
    $priority = calculatePriority($impact, $urgency);
    $serviceId = isset($input['service_id']) ? (int) $input['service_id'] : null;
    $service = null;
    if ($serviceId) {
        $statement = $db->prepare('SELECT id, team_id, sla_policy_id FROM services WHERE id = ? AND organization_id = ? AND active = 1');
        $statement->execute([$serviceId, $orgId]);
        $service = $statement->fetch() ?: null;
    }
    $policy = policyFor($orgId, $priority, $service['sla_policy_id'] ?? null);
    $calendar = calendarFor((int) $policy['calendar_id']);
    $holidays = holidaysFor((int) $policy['calendar_id']);
    $started = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $engine = new SlaEngine();
    $firstDue = $engine->calculateDueAt($started, (int) $policy['first_response_minutes'], $calendar, $holidays);
    $resolutionDue = $engine->calculateDueAt($started, (int) $policy['resolution_minutes'], $calendar, $holidays);
    $number = nextTicketNumber($db);
    $teamId = $service['team_id'] ?? null;
    $statement = $db->prepare('INSERT INTO tickets (organization_id, number, customer_id, requester_id, created_by, title, description, type, category, service_id, impact, urgency, priority, status, team_id, contract_id, sla_policy_id, sla_policy_version, first_response_due_at, resolution_due_at, first_response_status, resolution_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $statement->execute([$orgId, $number, $customerId, $user['id'], $user['id'], $title, $description, $input['type'] ?? 'incident', $input['category'] ?? null, $serviceId, $impact, $urgency, $priority, 'new', $teamId, $input['contract_id'] ?? null, $policy['id'], $policy['version'], $firstDue->format('Y-m-d H:i:s'), $resolutionDue->format('Y-m-d H:i:s'), 'on_track', 'on_track', now(), now()]);
    $ticketId = (int) $db->lastInsertId();
    $message = $db->prepare('INSERT INTO ticket_messages (ticket_id, author_id, visibility, body, created_at) VALUES (?, ?, ?, ?, ?)');
    $message->execute([$ticketId, $user['id'], 'public', $description, now()]);
    writeEvent($ticketId, (int) $user['id'], 'ticket_created', ['priority' => $priority, 'sla_policy_id' => $policy['id']]);
    Auth::audit((int) $user['id'], 'created', 'tickets', $ticketId, null, ['number' => $number, 'priority' => $priority]);
    return ticket($user, $ticketId);
}

function ticket(array $user, int $id): array
{
    $db = Database::connection();
    [$where, $params] = scope($user, 't');
    $statement = $db->prepare("SELECT t.*, c.name AS customer_name, c.email AS customer_email, u.name AS assignee_name, te.name AS team_name, s.name AS service_name, sp.name AS sla_policy_name FROM tickets t LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN users u ON u.id = t.assignee_id LEFT JOIN teams te ON te.id = t.team_id LEFT JOIN services s ON s.id = t.service_id LEFT JOIN sla_policies sp ON sp.id = t.sla_policy_id WHERE t.id = ? AND {$where} LIMIT 1");
    $statement->execute([$id, ...$params]);
    $ticket = $statement->fetch();
    if (!$ticket) {
        Response::error('Chamado não encontrado ou sem permissão.', 404);
    }
    $messageSql = "SELECT m.id, m.visibility, m.body, m.created_at, u.name AS author_name, u.role AS author_role FROM ticket_messages m LEFT JOIN users u ON u.id = m.author_id WHERE m.ticket_id = ?";
    if ($user['role'] === 'customer') {
        $messageSql .= " AND m.visibility = 'public'";
    }
    $messageSql .= ' ORDER BY m.created_at ASC';
    $messages = $db->prepare($messageSql);
    $messages->execute([$id]);
    $eventSql = 'SELECT e.id, e.event_type, e.data, e.created_at, u.name AS user_name FROM ticket_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.ticket_id = ?';
    if ($user['role'] === 'customer') {
        $eventSql .= " AND e.event_type <> 'internal_note_added'";
    }
    $eventSql .= ' ORDER BY e.created_at ASC';
    $events = $db->prepare($eventSql);
    $events->execute([$id]);
    $attachments = $db->prepare('SELECT a.id, a.original_name, a.mime_type, a.size_bytes, a.created_at, u.name AS uploaded_by_name FROM attachments a LEFT JOIN users u ON u.id = a.uploaded_by WHERE a.ticket_id = ? ORDER BY a.created_at DESC');
    $attachments->execute([$id]);
    $ticket['messages'] = $messages->fetchAll();
    $ticket['events'] = $events->fetchAll();
    $ticket['attachments'] = $attachments->fetchAll();
    foreach ($ticket['events'] as &$event) {
        $event['data'] = $event['data'] ? json_decode($event['data'], true) : null;
    }
    return $ticket;
}

function updateTicket(array $user, int $id): array
{
    if ($user['role'] === 'customer' || $user['role'] === 'auditor') {
        Response::error('Seu perfil não pode alterar o chamado.', 403);
    }
    $db = Database::connection();
    [$where, $scopeParams] = scope($user, 't');
    $currentStatement = $db->prepare("SELECT t.* FROM tickets t WHERE t.id = ? AND {$where} LIMIT 1");
    $currentStatement->execute([$id, ...$scopeParams]);
    $current = $currentStatement->fetch();
    if (!$current) {
        Response::error('Chamado não encontrado.', 404);
    }
    $input = input();
    $allowed = ['status', 'priority', 'team_id', 'assignee_id'];
    $changes = [];
    $params = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $input)) {
            $value = $input[$field] === '' ? null : $input[$field];
            if ($field === 'priority' && !in_array($value, ['P1', 'P2', 'P3', 'P4'], true)) {
                Response::error('Prioridade inválida.', 422);
            }
            $changes[$field] = $value;
        }
    }
    if (!$changes) {
        return ticket($user, $id);
    }
    if (($changes['status'] ?? null) === 'resolved') {
        $changes['resolved_at'] = now();
    }
    if (($changes['status'] ?? null) === 'closed') {
        $changes['closed_at'] = now();
    }
    $sets = [];
    foreach ($changes as $field => $value) {
        $sets[] = "{$field} = ?";
        $params[] = $value;
    }
    $sets[] = 'updated_at = ?';
    $params[] = now();
    $params[] = $id;
    $db->prepare('UPDATE tickets SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    writeEvent($id, (int) $user['id'], 'ticket_updated', $changes);
    Auth::audit((int) $user['id'], 'updated', 'tickets', $id, $current, $changes);
    return ticket($user, $id);
}

function addMessage(array $user, int $id): array
{
    if ($user['role'] === 'auditor') {
        Response::error('Seu perfil possui acesso somente para consulta.', 403);
    }
    $current = ticket($user, $id);
    $input = input();
    $body = trim((string) ($input['body'] ?? ''));
    $visibility = (string) ($input['visibility'] ?? 'public');
    if ($body === '') {
        Response::error('A mensagem não pode ficar vazia.', 422);
    }
    if ($user['role'] === 'customer') {
        $visibility = 'public';
    }
    $db = Database::connection();
    $db->prepare('INSERT INTO ticket_messages (ticket_id, author_id, visibility, body, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$id, $user['id'], $visibility === 'internal' ? 'internal' : 'public', $body, now()]);
    if ($visibility === 'public' && $user['role'] !== 'customer' && !$current['first_response_at']) {
        $db->prepare("UPDATE tickets SET first_response_at = ?, first_response_status = 'on_track', updated_at = ? WHERE id = ?")->execute([now(), now(), $id]);
    } else {
        $db->prepare('UPDATE tickets SET updated_at = ? WHERE id = ?')->execute([now(), $id]);
    }
    writeEvent($id, (int) $user['id'], $visibility === 'internal' ? 'internal_note_added' : 'message_added', ['visibility' => $visibility]);
    Auth::audit((int) $user['id'], 'message_added', 'tickets', $id, null, ['visibility' => $visibility]);
    return ticket($user, $id);
}

function uploadAttachments(array $user, int $ticketId): array
{
    if (in_array($user['role'], ['auditor'], true)) {
        Response::error('Seu perfil pode consultar o chamado, mas não pode anexar arquivos.', 403);
    }

    ticket($user, $ticketId);
    $files = $_FILES['attachments'] ?? null;
    if (!is_array($files) || !isset($files['name'])) {
        Response::error('Selecione pelo menos um arquivo.', 422);
    }

    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $types = is_array($files['type'] ?? null) ? $files['type'] : [($files['type'] ?? '')];
    $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
    $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];
    $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
    if (count($names) > 5) {
        Response::error('Você pode enviar no máximo 5 arquivos por vez.', 422);
    }

    $maxBytes = max(1, (int) Env::get('UPLOAD_MAX_MB', 10)) * 1024 * 1024;
    $allowed = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];
    $finfo = class_exists('finfo') ? new \finfo(FILEINFO_MIME_TYPE) : null;
    $validated = [];
    foreach ($names as $index => $originalName) {
        $error = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            Response::error('Não foi possível receber o arquivo enviado.', 422);
        }
        $tmpName = (string) ($tmpNames[$index] ?? '');
        $size = (int) ($sizes[$index] ?? 0);
        $safeName = trim(basename((string) $originalName));
        $extension = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if ($safeName === '' || !isset($allowed[$extension])) {
            Response::error('Tipo de arquivo não permitido. Use PDF, imagens, documentos, planilhas, TXT, CSV ou ZIP.', 422);
        }
        if ($size < 1 || $size > $maxBytes) {
            Response::error('Cada arquivo deve ter no máximo ' . (int) Env::get('UPLOAD_MAX_MB', 10) . ' MB.', 422);
        }
        if (!is_uploaded_file($tmpName) && !is_file($tmpName)) {
            Response::error('Arquivo temporário inválido.', 422);
        }
        $mime = $finfo?->file($tmpName) ?: (string) ($types[$index] ?? 'application/octet-stream');
        if (!in_array($mime, $allowed[$extension], true)) {
            Response::error('O conteúdo de "' . $safeName . '" não corresponde ao tipo informado.', 422);
        }
        $validated[] = ['name' => mb_substr($safeName, 0, 255), 'extension' => $extension, 'mime' => $mime, 'size' => $size, 'tmp' => $tmpName];
    }

    $directory = dirname(__DIR__) . '/storage/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        Response::error('Não foi possível preparar o armazenamento dos anexos.', 500);
    }
    $db = Database::connection();
    $created = [];
    try {
        foreach ($validated as $file) {
            $storedName = bin2hex(random_bytes(16)) . '.' . $file['extension'];
            $target = $directory . DIRECTORY_SEPARATOR . $storedName;
            if (!move_uploaded_file($file['tmp'], $target)) {
                throw new RuntimeException('Não foi possível salvar o anexo.');
            }
            $created[] = $target;
            @chmod($target, 0600);
            $statement = $db->prepare('INSERT INTO attachments (ticket_id, message_id, uploaded_by, original_name, stored_name, mime_type, size_bytes, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?)');
            $statement->execute([$ticketId, $user['id'], $file['name'], $storedName, $file['mime'], $file['size'], now()]);
        }
    } catch (Throwable $exception) {
        foreach ($created as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        throw $exception;
    }
    writeEvent($ticketId, (int) $user['id'], 'attachment_added', ['count' => count($validated)]);
    Auth::audit((int) $user['id'], 'attachment_added', 'tickets', $ticketId, null, ['count' => count($validated)]);
    return ['message' => count($validated) . ' anexo(s) enviado(s).', 'ticket' => ticket($user, $ticketId)];
}

function downloadAttachment(array $user, int $attachmentId): never
{
    $db = Database::connection();
    [$where, $params] = scope($user, 't');
    $statement = $db->prepare("SELECT a.original_name, a.stored_name, a.mime_type, a.size_bytes FROM attachments a INNER JOIN tickets t ON t.id = a.ticket_id WHERE a.id = ? AND {$where} LIMIT 1");
    $statement->execute([$attachmentId, ...$params]);
    $attachment = $statement->fetch();
    if (!$attachment) {
        Response::error('Anexo não encontrado ou sem permissão.', 404);
    }
    $path = dirname(__DIR__) . '/storage/uploads/' . basename((string) $attachment['stored_name']);
    if (!is_file($path)) {
        Response::error('O arquivo anexado não está disponível no armazenamento.', 404);
    }
    $downloadName = str_replace(["\r", "\n", '"'], '', (string) $attachment['original_name']);
    header('Content-Type: ' . $attachment['mime_type']);
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, "\\\"") . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function knowledge(array $user): array
{
    $db = Database::connection();
    if ($user['role'] === 'superadmin') {
        $statement = $db->query("SELECT id, title, content, visibility, status, updated_at FROM knowledge_articles WHERE status = 'published' ORDER BY updated_at DESC");
        return $statement->fetchAll();
    }
    $statement = $db->prepare("SELECT id, title, content, visibility, status, updated_at FROM knowledge_articles WHERE organization_id = ? AND status = 'published' AND (visibility = 'public' OR ? <> 'customer') ORDER BY updated_at DESC");
    $statement->execute([(int) $user['organization_id'], $user['role']]);
    return $statement->fetchAll();
}

function auditLogs(array $user): array
{
    $db = Database::connection();
    if ($user['role'] === 'superadmin') {
        $statement = $db->query('SELECT a.id, a.action, a.entity_type, a.entity_id, a.before_data, a.after_data, a.created_at, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 100');
        return $statement->fetchAll();
    }
    $statement = $db->prepare('SELECT a.id, a.action, a.entity_type, a.entity_id, a.before_data, a.after_data, a.created_at, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN tickets t ON t.id = a.entity_id AND a.entity_type = \'tickets\' WHERE (t.organization_id = ? OR a.user_id = ?) ORDER BY a.created_at DESC LIMIT 100');
    $statement->execute([(int) $user['organization_id'], (int) $user['id']]);
    return $statement->fetchAll();
}

function organizationScope(array $user, string $alias = ''): array
{
    if ($user['role'] === 'superadmin') {
        return ['1=1', []];
    }
    $column = $alias !== '' ? $alias . '.organization_id' : 'organization_id';
    return [$column . ' = ?', [(int) $user['organization_id']]];
}

function authorizeModule(array $user, string $resource, string $action = 'view'): void
{
    $permissions = [
        'customers' => [
            'view' => ['superadmin', 'admin', 'manager', 'supervisor', 'auditor'],
            'create' => ['superadmin', 'admin', 'manager', 'supervisor'],
        ],
        'contracts' => [
            'view' => ['superadmin', 'admin', 'manager', 'auditor'],
            'create' => ['superadmin', 'admin', 'manager'],
        ],
        'teams' => [
            'view' => ['superadmin', 'admin', 'manager', 'supervisor', 'auditor'],
            'create' => ['superadmin', 'admin', 'manager', 'supervisor'],
        ],
        'sla' => [
            'view' => ['superadmin', 'admin', 'manager', 'auditor'],
            'create' => ['superadmin', 'admin', 'manager'],
        ],
        'assets' => [
            'view' => ['superadmin', 'admin', 'manager', 'supervisor', 'technician', 'auditor'],
            'create' => ['superadmin', 'admin', 'manager', 'supervisor', 'technician'],
        ],
        'reports' => [
            'view' => ['superadmin', 'admin', 'manager', 'supervisor', 'auditor'],
        ],
        'automations' => [
            'view' => ['superadmin', 'admin', 'manager'],
            'create' => ['superadmin', 'admin', 'manager'],
        ],
        'audit' => [
            'view' => ['superadmin', 'admin', 'manager', 'auditor'],
        ],
    ];
    if (!in_array($user['role'], $permissions[$resource][$action] ?? [], true)) {
        Response::error('Seu perfil não possui acesso a este módulo.', 403);
    }
}

function moduleData(array $user, string $resource): array
{
    $db = Database::connection();
    [$where, $params] = organizationScope($user, 'x');
    return match ($resource) {
        'customers' => fetchRows($db, "SELECT x.id, x.name, x.legal_name, x.document, x.email, x.phone, x.city, x.state, x.status, x.created_at, (SELECT COUNT(*) FROM tickets t WHERE t.customer_id = x.id) AS ticket_count FROM customers x WHERE {$where} ORDER BY x.name", $params),
        'contracts' => fetchRows($db, "SELECT x.id, x.code, x.plan_name, x.starts_on, x.ends_on, x.status, x.notes, c.name AS customer_name, cal.name AS calendar_name, (SELECT COUNT(*) FROM tickets t WHERE t.contract_id = x.id) AS ticket_count FROM contracts x INNER JOIN customers c ON c.id = x.customer_id LEFT JOIN sla_calendars cal ON cal.id = x.calendar_id WHERE {$where} ORDER BY x.ends_on IS NULL DESC, x.ends_on DESC", $params),
        'teams' => fetchRows($db, "SELECT x.id, x.name, x.department, x.active, u.name AS supervisor_name, (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = x.id) AS member_count, (SELECT COUNT(*) FROM tickets t WHERE t.team_id = x.id AND t.status NOT IN ('resolved','closed','cancelled')) AS open_tickets FROM teams x LEFT JOIN users u ON u.id = x.supervisor_id WHERE {$where} ORDER BY x.name", $params),
        'sla' => fetchRows($db, "SELECT x.id, x.name, x.priority, x.first_response_minutes, x.resolution_minutes, x.pause_statuses, x.alert_thresholds, x.version, x.active, cal.name AS calendar_name, cal.type AS calendar_type FROM sla_policies x INNER JOIN sla_calendars cal ON cal.id = x.calendar_id WHERE {$where} ORDER BY FIELD(x.priority, 'P1','P2','P3','P4')", $params),
        'assets' => fetchRows($db, "SELECT x.id, x.asset_code, x.name, x.type, x.manufacturer, x.model, x.serial_number, x.location, x.status, c.name AS customer_name, (SELECT COUNT(*) FROM tickets t WHERE t.customer_id = x.customer_id AND t.category = x.type) AS incident_count FROM assets x LEFT JOIN customers c ON c.id = x.customer_id WHERE {$where} ORDER BY x.name", $params),
        'automations' => fetchRows($db, "SELECT x.id, x.name, x.event_type, x.conditions, x.actions, x.active, x.created_at, (SELECT COUNT(*) FROM automation_executions e WHERE e.rule_id = x.id) AS execution_count FROM automation_rules x WHERE {$where} ORDER BY x.name", $params),
        'reports' => reportData($user),
        default => [],
    };
}

function fetchRows(PDO $db, string $sql, array $params): array
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
    foreach ($rows as &$row) {
        foreach (['pause_statuses', 'alert_thresholds', 'conditions', 'actions'] as $jsonField) {
            if (array_key_exists($jsonField, $row) && $row[$jsonField] !== null && $row[$jsonField] !== '') {
                $row[$jsonField] = json_decode((string) $row[$jsonField], true);
            }
        }
    }
    return $rows;
}

function reportData(array $user): array
{
    $db = Database::connection();
    [$where, $params] = organizationScope($user, 't');
    $cards = $db->prepare("SELECT COUNT(*) AS total, SUM(t.status NOT IN ('resolved','closed','cancelled')) AS open_count, SUM(t.resolution_status = 'violated') AS breached, AVG(CASE WHEN t.resolved_at IS NOT NULL THEN t.resolution_elapsed_minutes END) AS avg_resolution, AVG(CASE WHEN t.first_response_at IS NOT NULL THEN t.first_response_elapsed_minutes END) AS avg_first_response FROM tickets t WHERE {$where}");
    $cards->execute($params);
    $byService = fetchRows($db, "SELECT COALESCE(s.name, 'Sem serviço') AS label, COUNT(*) AS value FROM tickets t LEFT JOIN services s ON s.id = t.service_id WHERE {$where} GROUP BY COALESCE(s.name, 'Sem serviço') ORDER BY value DESC", $params);
    $byCustomer = fetchRows($db, "SELECT COALESCE(c.name, 'Sem cliente') AS label, COUNT(*) AS value FROM tickets t LEFT JOIN customers c ON c.id = t.customer_id WHERE {$where} GROUP BY COALESCE(c.name, 'Sem cliente') ORDER BY value DESC LIMIT 8", $params);
    $byMonth = fetchRows($db, "SELECT DATE_FORMAT(t.created_at, '%Y-%m') AS label, COUNT(*) AS value FROM tickets t WHERE {$where} AND t.created_at >= UTC_DATE() - INTERVAL 5 MONTH GROUP BY DATE_FORMAT(t.created_at, '%Y-%m') ORDER BY label", $params);
    return ['cards' => $cards->fetch() ?: [], 'by_service' => $byService, 'by_customer' => $byCustomer, 'by_month' => $byMonth];
}

function createModule(array $user, string $resource): array
{
    authorizeModule($user, $resource, 'create');
    $input = input();
    $db = Database::connection();
    $organizationId = (int) ($user['organization_id'] ?: ($input['organization_id'] ?? 1));
    $createdAt = now();

    if ($resource === 'customers') {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') Response::error('Informe o nome do cliente.', 422);
        $statement = $db->prepare('INSERT INTO customers (organization_id, name, legal_name, document, email, phone, address, city, state, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$organizationId, $name, $input['legal_name'] ?? null, $input['document'] ?? null, $input['email'] ?? null, $input['phone'] ?? null, $input['address'] ?? null, $input['city'] ?? null, $input['state'] ?? null, $input['status'] ?? 'active', $createdAt, $createdAt]);
    } elseif ($resource === 'contracts') {
        $statement = $db->prepare('INSERT INTO contracts (organization_id, customer_id, code, plan_name, starts_on, ends_on, status, calendar_id, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $calendarId = !empty($input['calendar_id']) ? (int) $input['calendar_id'] : null;
        $endsOn = !empty($input['ends_on']) ? $input['ends_on'] : null;
        $statement->execute([$organizationId, (int) ($input['customer_id'] ?? 0), trim((string) ($input['code'] ?? '')), trim((string) ($input['plan_name'] ?? 'Plano de suporte')), $input['starts_on'] ?? date('Y-m-d'), $endsOn, $input['status'] ?? 'active', $calendarId, $input['notes'] ?? null, $createdAt, $createdAt]);
    } elseif ($resource === 'teams') {
        $statement = $db->prepare('INSERT INTO teams (organization_id, name, department, supervisor_id, active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)');
        $statement->execute([$organizationId, trim((string) ($input['name'] ?? '')), $input['department'] ?? 'Suporte', $input['supervisor_id'] ?: null, $createdAt, $createdAt]);
    } elseif ($resource === 'sla') {
        $statement = $db->prepare('INSERT INTO sla_policies (organization_id, calendar_id, name, priority, first_response_minutes, resolution_minutes, pause_statuses, alert_thresholds, version, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?)');
        $statement->execute([$organizationId, (int) ($input['calendar_id'] ?? 0), trim((string) ($input['name'] ?? 'Nova política')), $input['priority'] ?? 'P3', (int) ($input['first_response_minutes'] ?? 240), (int) ($input['resolution_minutes'] ?? 1440), json_encode($input['pause_statuses'] ?? ['waiting_customer']), json_encode([50, 75, 90, 100]), $createdAt, $createdAt]);
    } elseif ($resource === 'assets') {
        $statement = $db->prepare('INSERT INTO assets (organization_id, customer_id, asset_code, name, type, manufacturer, model, serial_number, location, status, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$organizationId, $input['customer_id'] ?: null, trim((string) ($input['asset_code'] ?? '')), trim((string) ($input['name'] ?? '')), $input['type'] ?? 'equipamento', $input['manufacturer'] ?? null, $input['model'] ?? null, $input['serial_number'] ?? null, $input['location'] ?? null, $input['status'] ?? 'active', $input['notes'] ?? null, $createdAt, $createdAt]);
    } elseif ($resource === 'automations') {
        $statement = $db->prepare('INSERT INTO automation_rules (organization_id, name, event_type, conditions, actions, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)');
        $statement->execute([$organizationId, trim((string) ($input['name'] ?? 'Nova automação')), $input['event_type'] ?? 'ticket_created', json_encode($input['conditions'] ?? []), json_encode($input['actions'] ?? []), $createdAt, $createdAt]);
    } else {
        Response::error('Módulo não suportado.', 422);
    }

    $id = (int) $db->lastInsertId();
    Auth::audit((int) $user['id'], 'created', $resource, $id, null, $input);
    return ['id' => $id, 'resource' => $resource, 'message' => 'Registro criado com sucesso.'];
}

function calculatePriority(string $impact, string $urgency): string
{
    $matrix = [
        'high:high' => 'P1', 'high:medium' => 'P2', 'high:low' => 'P2',
        'medium:high' => 'P2', 'medium:medium' => 'P3', 'medium:low' => 'P3',
        'low:high' => 'P3', 'low:medium' => 'P4', 'low:low' => 'P4',
    ];
    return $matrix[$impact . ':' . $urgency] ?? 'P3';
}

function policyFor(int $organizationId, string $priority, ?int $servicePolicyId = null): array
{
    $db = Database::connection();
    if ($servicePolicyId) {
        $statement = $db->prepare('SELECT * FROM sla_policies WHERE id = ? AND (organization_id = ? OR organization_id IS NULL) AND active = 1 LIMIT 1');
        $statement->execute([$servicePolicyId, $organizationId]);
        $policy = $statement->fetch();
        if ($policy) {
            return $policy;
        }
    }
    $statement = $db->prepare('SELECT * FROM sla_policies WHERE organization_id = ? AND priority = ? AND active = 1 ORDER BY version DESC LIMIT 1');
    $statement->execute([$organizationId, $priority]);
    $policy = $statement->fetch();
    if (!$policy) {
        Response::error('Não há política de SLA configurada para esta prioridade.', 422);
    }
    return $policy;
}

function calendarFor(int $id): array
{
    $statement = Database::connection()->prepare('SELECT * FROM sla_calendars WHERE id = ? LIMIT 1');
    $statement->execute([$id]);
    $calendar = $statement->fetch();
    if (!$calendar) {
        Response::error('Calendário de SLA não encontrado.', 500);
    }
    $calendar['weekdays'] = json_decode((string) $calendar['weekdays'], true) ?: [1, 2, 3, 4, 5];
    return $calendar;
}

function holidaysFor(int $calendarId): array
{
    $statement = Database::connection()->prepare('SELECT holiday_date FROM sla_holidays WHERE calendar_id = ?');
    $statement->execute([$calendarId]);
    return array_column($statement->fetchAll(), 'holiday_date');
}

function nextTicketNumber(PDO $db): string
{
    $prefix = 'TKT-' . gmdate('Ymd') . '-';
    $statement = $db->prepare('SELECT number FROM tickets WHERE number LIKE ? ORDER BY id DESC LIMIT 1');
    $statement->execute([$prefix . '%']);
    $last = (string) ($statement->fetchColumn() ?: '');
    $sequence = $last !== '' ? ((int) substr($last, -4)) + 1 : 1;
    return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
}

function writeEvent(int $ticketId, int $userId, string $type, array $data): void
{
    $statement = Database::connection()->prepare('INSERT INTO ticket_events (ticket_id, user_id, event_type, data, created_at) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([$ticketId, $userId, $type, json_encode($data, JSON_UNESCAPED_UNICODE), now()]);
}
