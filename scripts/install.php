<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$env = [];
$envPath = $root . DIRECTORY_SEPARATOR . '.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"");
    }
}

$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$database = $env['DB_DATABASE'] ?? 'support_sla';
$username = $env['DB_USERNAME'] ?? 'root';
$password = $env['DB_PASSWORD'] ?? '';

try {
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $quotedDatabase = '`' . str_replace('`', '``', $database) . '`';
    $pdo->exec("CREATE DATABASE IF NOT EXISTS {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE {$quotedDatabase}");
    $schema = file_get_contents($root . '/database/schema.sql');
    foreach (array_filter(array_map('trim', explode(';', (string) $schema))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
    seed($pdo);
    echo "Support SLA instalado com sucesso em {$database}." . PHP_EOL;
    echo "Acesse o domínio configurado no ServBay e use as contas listadas no README." . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(STDERR, "Falha na instalação: {$exception->getMessage()}" . PHP_EOL);
    exit(1);
}

function insert(PDO $pdo, string $sql, array $params = []): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $pdo->lastInsertId();
}

function seed(PDO $pdo): void
{
    $now = gmdate('Y-m-d H:i:s');
    $passwordHash = password_hash('Admin@12345', PASSWORD_DEFAULT);
    $pdo->beginTransaction();

    try {
        $orgDemo = insert($pdo, 'INSERT INTO organizations (name, slug, timezone, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', ['Support SLA Demo', 'support-sla-demo', 'America/Sao_Paulo', 'active', $now, $now]);
        $orgSecond = insert($pdo, 'INSERT INTO organizations (name, slug, timezone, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', ['Norte Tecnologia', 'norte-tecnologia', 'America/Sao_Paulo', 'active', $now, $now]);

        $roles = [
            ['Super Administrador', 'superadmin@supportsla.test', 'superadmin', null],
            ['Administrador da Organização', 'admin@supportsla.test', 'admin', $orgDemo],
            ['Gestor de Suporte', 'gestor@supportsla.test', 'manager', $orgDemo],
            ['Supervisor de Atendimento', 'supervisor@supportsla.test', 'supervisor', $orgDemo],
            ['Técnico Principal', 'tecnico@supportsla.test', 'technician', $orgDemo],
            ['Cliente Demonstração', 'cliente@supportsla.test', 'customer', $orgDemo],
            ['Auditor de Operações', 'auditor@supportsla.test', 'auditor', $orgDemo],
        ];
        $users = [];
        foreach ($roles as [$name, $email, $role, $organizationId]) {
            $users[$email] = insert($pdo, 'INSERT INTO users (organization_id, name, email, password_hash, role, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)', [$organizationId, $name, $email, $passwordHash, $role, $now, $now]);
        }

        $technicians = [];
        for ($i = 1; $i <= 8; $i++) {
            $email = "tecnico{$i}@supportsla.test";
            $technicians[] = insert($pdo, 'INSERT INTO users (organization_id, name, email, password_hash, role, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)', [$orgDemo, "Técnico {$i}", $email, $passwordHash, 'technician', $now, $now]);
        }

        $customers = [];
        $customerNames = ['Acme Industrial', 'Comercial Aurora', 'Clínica Horizonte'];
        foreach ($customerNames as $index => $name) {
            $customers[] = insert($pdo, 'INSERT INTO customers (organization_id, name, legal_name, document, email, phone, city, state, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$orgDemo, $name, $name . ' LTDA', '00.000.000/000' . ($index + 1) . '-00', 'contato' . ($index + 1) . '@cliente.test', '(47) 3333-000' . ($index + 1), 'Balneário Piçarras', 'SC', 'active', $now, $now]);
        }

        $clientUsers = [];
        for ($i = 1; $i <= 15; $i++) {
            $customerId = $customers[($i - 1) % count($customers)];
            $email = "cliente{$i}@supportsla.test";
            $clientUsers[] = insert($pdo, 'INSERT INTO users (organization_id, name, email, password_hash, role, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)', [$orgDemo, "Contato Cliente {$i}", $email, $passwordHash, 'customer', $now, $now]);
            insert($pdo, 'INSERT INTO customer_contacts (customer_id, user_id, name, email, phone, authorized, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)', [$customerId, $clientUsers[array_key_last($clientUsers)], "Contato Cliente {$i}", $email, '(47) 99999-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), $now]);
        }

        $calendar247 = insert($pdo, 'INSERT INTO sla_calendars (organization_id, name, type, timezone, work_start, work_end, weekdays, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$orgDemo, 'Atendimento 24x7', '24x7', 'America/Sao_Paulo', '00:00:00', '23:59:59', json_encode([1, 2, 3, 4, 5, 6, 7]), $now, $now]);
        $calendarBusiness = insert($pdo, 'INSERT INTO sla_calendars (organization_id, name, type, timezone, work_start, work_end, weekdays, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$orgDemo, 'Horário Comercial', 'business', 'America/Sao_Paulo', '08:00:00', '18:00:00', json_encode([1, 2, 3, 4, 5]), $now, $now]);
        insert($pdo, 'INSERT INTO sla_holidays (organization_id, calendar_id, holiday_date, name, created_at) VALUES (?, ?, ?, ?, ?)', [$orgDemo, $calendarBusiness, date('Y') . '-12-25', 'Natal', $now]);

        $policies = [];
        $policyData = [
            ['P1', 'Crítica — 24x7', $calendar247, 15, 240, []],
            ['P2', 'Alta — 24x7', $calendar247, 60, 480, []],
            ['P3', 'Média — Comercial', $calendarBusiness, 240, 1440, ['waiting_customer']],
            ['P4', 'Baixa — Comercial', $calendarBusiness, 480, 4320, ['waiting_customer']],
        ];
        foreach ($policyData as [$priority, $name, $calendarId, $first, $resolution, $pauses]) {
            $policies[$priority] = insert($pdo, 'INSERT INTO sla_policies (organization_id, calendar_id, name, priority, first_response_minutes, resolution_minutes, pause_statuses, alert_thresholds, version, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?)', [$orgDemo, $calendarId, $name, $priority, $first, $resolution, json_encode($pauses), json_encode([50, 75, 90, 100]), $now, $now]);
        }

        $teams = [];
        foreach (['Service Desk', 'Infraestrutura', 'Sistemas'] as $teamName) {
            $teams[] = insert($pdo, 'INSERT INTO teams (organization_id, name, department, supervisor_id, active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)', [$orgDemo, $teamName, 'Tecnologia', $users['supervisor@supportsla.test'], $now, $now]);
        }
        foreach ($technicians as $index => $technicianId) {
            insert($pdo, 'INSERT INTO team_members (team_id, user_id, availability, capacity) VALUES (?, ?, ?, ?)', [$teams[$index % 3], $technicianId, 'available', 10]);
        }
        insert($pdo, 'INSERT INTO team_members (team_id, user_id, availability, capacity) VALUES (?, ?, ?, ?)', [$teams[0], $users['tecnico@supportsla.test'], 'available', 10]);

        $categories = [];
        foreach (['Hardware', 'Software', 'Redes', 'Acessos'] as $category) {
            $categories[] = insert($pdo, 'INSERT INTO service_categories (organization_id, name, created_at) VALUES (?, ?, ?)', [$orgDemo, $category, $now]);
        }
        $services = [];
        foreach ([['Suporte a computadores', 'Hardware', 'P3'], ['Suporte a sistemas', 'Software', 'P3'], ['Redes e conectividade', 'Redes', 'P2'], ['Recuperação de acesso', 'Acessos', 'P2'], ['Incidente crítico', 'Software', 'P1']] as [$serviceName, $categoryName, $priority]) {
            $categoryId = $categories[array_search($categoryName, ['Hardware', 'Software', 'Redes', 'Acessos'], true)];
            $services[] = insert($pdo, 'INSERT INTO services (organization_id, category_id, sla_policy_id, team_id, name, description, default_priority, requires_approval, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1, ?, ?)', [$orgDemo, $categoryId, $policies[$priority], $teams[count($services) % 3], $serviceName, 'Serviço de demonstração do catálogo Support SLA.', $priority, $now, $now]);
        }

        $contracts = [];
        for ($i = 0; $i < 2; $i++) {
            $contracts[] = insert($pdo, 'INSERT INTO contracts (organization_id, customer_id, code, plan_name, starts_on, ends_on, status, calendar_id, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$orgDemo, $customers[$i], 'CTR-2026-00' . ($i + 1), $i === 0 ? 'Plano Enterprise' : 'Plano Business', date('Y') . '-01-01', date('Y') . '-12-31', 'active', $i === 0 ? $calendar247 : $calendarBusiness, 'Contrato de demonstração.', $now, $now]);
        }

        $statuses = ['new', 'triage', 'in_progress', 'waiting_customer', 'waiting_vendor', 'resolved', 'closed', 'cancelled'];
        $titles = ['Computador não inicia', 'Lentidão no sistema', 'Falha na VPN', 'Solicitação de novo usuário', 'Impressora sem conexão', 'Erro ao acessar relatório', 'Instabilidade na rede', 'Atualização de software'];
        $impactUrgency = [['high', 'high'], ['high', 'medium'], ['medium', 'medium'], ['low', 'medium'], ['low', 'low']];
        $firstResponseMinutesByPriority = ['P1' => 900, 'P2' => 3600, 'P3' => 14400, 'P4' => 28800];
        $resolutionMinutesByPriority = ['P1' => 14400, 'P2' => 28800, 'P3' => 86400, 'P4' => 259200];
        for ($i = 1; $i <= 60; $i++) {
            $priority = ['P1', 'P2', 'P3', 'P4'][$i % 4];
            [$impact, $urgency] = $impactUrgency[$i % count($impactUrgency)];
            $status = $statuses[$i % count($statuses)];
            $createdAt = gmdate('Y-m-d H:i:s', time() - ($i * 3600));
            $serviceId = $services[$i % count($services)];
            $assignee = $technicians[$i % count($technicians)];
            $number = 'TKT-' . date('Ymd') . '-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $dueFirst = gmdate('Y-m-d H:i:s', strtotime($createdAt) + $firstResponseMinutesByPriority[$priority]);
            $dueResolution = gmdate('Y-m-d H:i:s', strtotime($createdAt) + $resolutionMinutesByPriority[$priority]);
            $resolvedAt = in_array($status, ['resolved', 'closed'], true) ? gmdate('Y-m-d H:i:s', strtotime($createdAt) + 7200) : null;
            $closedAt = $status === 'closed' ? gmdate('Y-m-d H:i:s', strtotime($createdAt) + 10800) : null;
            $resolutionStatus = $i % 11 === 0 ? 'violated' : ($i % 5 === 0 ? 'at_risk' : 'on_track');
            $ticketId = insert($pdo, 'INSERT INTO tickets (organization_id, number, customer_id, requester_id, created_by, title, description, type, category, service_id, impact, urgency, priority, status, team_id, assignee_id, contract_id, sla_policy_id, sla_policy_version, first_response_due_at, resolution_due_at, first_response_at, resolved_at, closed_at, first_response_status, resolution_status, first_response_elapsed_minutes, resolution_elapsed_minutes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$orgDemo, $number, $customers[$i % count($customers)], $clientUsers[$i % count($clientUsers)], $users['admin@supportsla.test'], $titles[$i % count($titles)], 'Descrição fictícia do chamado para validação dos fluxos de atendimento e SLA.', 'incident', ['Hardware', 'Software', 'Redes', 'Acessos'][$i % 4], $serviceId, $impact, $urgency, $priority, $status, $teams[$i % 3], $assignee, $contracts[$i % 2], $policies[$priority], $dueFirst, $dueResolution, $i % 3 === 0 ? gmdate('Y-m-d H:i:s', strtotime($createdAt) + 1800) : null, $resolvedAt, $closedAt, $i % 3 === 0 ? 'on_track' : 'at_risk', $resolutionStatus, $i * 5, $i * 17, $createdAt, $now]);
            insert($pdo, 'INSERT INTO ticket_messages (ticket_id, author_id, visibility, body, created_at) VALUES (?, ?, ?, ?, ?)', [$ticketId, $clientUsers[$i % count($clientUsers)], 'public', 'Mensagem inicial do solicitante.', $createdAt]);
            insert($pdo, 'INSERT INTO ticket_events (ticket_id, user_id, event_type, data, created_at) VALUES (?, ?, ?, ?, ?)', [$ticketId, $users['admin@supportsla.test'], 'ticket_created', json_encode(['status' => $status, 'priority' => $priority]), $createdAt]);
        }

        $knowledgeCategory = insert($pdo, 'INSERT INTO knowledge_categories (organization_id, name, created_at) VALUES (?, ?, ?)', [$orgDemo, 'Primeiros passos', $now]);
        insert($pdo, 'INSERT INTO knowledge_articles (organization_id, category_id, author_id, title, content, status, visibility, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$orgDemo, $knowledgeCategory, $users['gestor@supportsla.test'], 'Como abrir um chamado eficiente', 'Informe o impacto, a urgência, o serviço afetado e os passos para reproduzir o problema.', 'published', 'public', $now, $now]);
        foreach ($customers as $index => $customerId) {
            insert($pdo, 'INSERT INTO assets (organization_id, customer_id, asset_code, name, type, manufacturer, model, serial_number, location, status, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$orgDemo, $customerId, 'AT-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT), 'Estação de trabalho ' . ($index + 1), 'desktop', 'Dell', 'OptiPlex', 'SN-DEMO-' . ($index + 1), 'Sede', 'active', 'Ativo fictício para demonstração.', $now, $now]);
        }
        insert($pdo, 'INSERT INTO notifications (user_id, type, title, body, data, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$users['gestor@supportsla.test'], 'sla_at_risk', 'Chamados próximos do SLA', 'Existem chamados que exigem atenção nesta janela.', json_encode(['count' => 6]), $now]);

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}
