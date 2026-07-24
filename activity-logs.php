<?php
declare(strict_types=1);

$pageTitle = 'Activity Logs';

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/activity-log.php';

$logType = (string)($_GET['log_type'] ?? 'business');

if (!in_array($logType, ['business', 'login'], true)) {
    $logType = 'business';
}

$search = trim((string)($_GET['q'] ?? ''));
$moduleKey = trim((string)($_GET['module_key'] ?? ''));
$actionType = trim((string)($_GET['action_type'] ?? ''));
$userId = max(0, (int)($_GET['user_id'] ?? 0));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$perPage = max(10, min(100, (int)($_GET['per_page'] ?? 25)));
$page = max(1, (int)($_GET['page'] ?? 1));

$validDate = static function (string $date): bool {
    if ($date === '') {
        return false;
    }

    $value = DateTimeImmutable::createFromFormat('Y-m-d', $date);

    return $value !== false && $value->format('Y-m-d') === $date;
};

if ($dateFrom !== '' && !$validDate($dateFrom)) {
    $dateFrom = '';
}

if ($dateTo !== '' && !$validDate($dateTo)) {
    $dateTo = '';
}

if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$userStmt = $pdo->query(
    "SELECT id, full_name, username
     FROM users
     ORDER BY full_name, username"
);
$users = $userStmt->fetchAll();

$moduleStmt = $pdo->prepare(
    "SELECT DISTINCT module_key
     FROM activity_logs
     WHERE business_id = ?
     ORDER BY module_key"
);
$moduleStmt->execute([$currentBusinessId]);
$modules = $moduleStmt->fetchAll(PDO::FETCH_COLUMN);

$actionStmt = $pdo->prepare(
    "SELECT DISTINCT action_type
     FROM activity_logs
     WHERE business_id = ?
     ORDER BY action_type"
);
$actionStmt->execute([$currentBusinessId]);
$actions = $actionStmt->fetchAll(PDO::FETCH_COLUMN);

$where = [];
$params = [];

if ($logType === 'business') {
    $where[] = 'al.business_id = ?';
    $params[] = $currentBusinessId;

    if ($search !== '') {
        $where[] =
            "(
                al.description LIKE ?
                OR al.module_key LIKE ?
                OR al.action_type LIKE ?
                OR al.entity_type LIKE ?
                OR CAST(al.entity_id AS CHAR) LIKE ?
                OR u.full_name LIKE ?
                OR u.username LIKE ?
                OR al.ip_address LIKE ?
            )";

        $like = '%' . $search . '%';

        for ($index = 0; $index < 8; $index++) {
            $params[] = $like;
        }
    }

    if ($moduleKey !== '') {
        $where[] = 'al.module_key = ?';
        $params[] = $moduleKey;
    }

    if ($actionType !== '') {
        $where[] = 'al.action_type = ?';
        $params[] = $actionType;
    }

    if ($userId > 0) {
        $where[] = 'al.user_id = ?';
        $params[] = $userId;
    }

    if ($dateFrom !== '') {
        $where[] = 'al.created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== '') {
        $where[] = 'al.created_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM activity_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE {$whereSql}"
    );
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = ($page - 1) * $perPage;

    $listStmt = $pdo->prepare(
        "SELECT
            al.*,
            u.full_name,
            u.username,
            u.role_key
         FROM activity_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE {$whereSql}
         ORDER BY al.created_at DESC, al.id DESC
         LIMIT {$perPage}
         OFFSET {$offset}"
    );
    $listStmt->execute($params);
    $logs = $listStmt->fetchAll();

    $summaryStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_count,
            SUM(action_type = 'create') AS create_count,
            SUM(action_type = 'update') AS update_count,
            SUM(action_type IN ('delete', 'cancel', 'reverse')) AS destructive_count,
            COUNT(DISTINCT user_id) AS user_count
         FROM activity_logs
         WHERE business_id = ?
           AND created_at >= CURRENT_DATE()"
    );
    $summaryStmt->execute([$currentBusinessId]);
    $summary = $summaryStmt->fetch() ?: [];
} else {
    $where = ['1 = 1'];
    $params = [];

    if ($search !== '') {
        $where[] =
            "(
                lal.username_entered LIKE ?
                OR lal.event_type LIKE ?
                OR u.full_name LIKE ?
                OR u.username LIKE ?
                OR lal.ip_address LIKE ?
            )";

        $like = '%' . $search . '%';

        for ($index = 0; $index < 5; $index++) {
            $params[] = $like;
        }
    }

    if ($actionType !== '') {
        $where[] = 'lal.event_type = ?';
        $params[] = $actionType;
    }

    if ($userId > 0) {
        $where[] = 'lal.user_id = ?';
        $params[] = $userId;
    }

    if ($dateFrom !== '') {
        $where[] = 'lal.event_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== '') {
        $where[] = 'lal.event_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM login_activity_logs lal
         LEFT JOIN users u ON u.id = lal.user_id
         WHERE {$whereSql}"
    );
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = ($page - 1) * $perPage;

    $listStmt = $pdo->prepare(
        "SELECT
            lal.*,
            u.full_name,
            u.username,
            u.role_key
         FROM login_activity_logs lal
         LEFT JOIN users u ON u.id = lal.user_id
         WHERE {$whereSql}
         ORDER BY lal.event_at DESC, lal.id DESC
         LIMIT {$perPage}
         OFFSET {$offset}"
    );
    $listStmt->execute($params);
    $logs = $listStmt->fetchAll();

    $summaryStmt = $pdo->query(
        "SELECT
            COUNT(*) AS total_count,
            SUM(event_type = 'login_success') AS create_count,
            SUM(event_type = 'login_failed') AS update_count,
            SUM(event_type = 'logout') AS destructive_count,
            COUNT(DISTINCT user_id) AS user_count
         FROM login_activity_logs
         WHERE event_at >= CURRENT_DATE()"
    );
    $summary = $summaryStmt->fetch() ?: [];
}

$queryWithoutPage = $_GET;
unset($queryWithoutPage['page']);

$buildPageUrl = static function (
    int $targetPage,
    array $query
): string {
    $query['page'] = $targetPage;

    return '?' . http_build_query($query);
};

$pretty = static function (string $value): string {
    return ucwords(str_replace(['_', '-'], ' ', $value));
};

$actionClass = static function (string $action): string {
    return match ($action) {
        'create', 'login_success' => 'log-action-create',
        'update', 'password_changed' => 'log-action-update',
        'delete', 'cancel', 'reverse', 'login_failed' => 'log-action-danger',
        'logout' => 'log-action-logout',
        default => 'log-action-neutral',
    };
};

include __DIR__ . '/includes/layout-start.php';
?>

<style>
.activity-filter-grid {
    display: grid;
    grid-template-columns:
        minmax(230px, 2fr)
        repeat(3, minmax(150px, 1fr));
    gap: 12px;
}

.activity-filter-bottom {
    display: grid;
    grid-template-columns:
        minmax(150px, 1fr)
        minmax(150px, 1fr)
        minmax(110px, .6fr)
        auto auto;
    gap: 12px;
    margin-top: 12px;
    align-items: end;
}

.activity-tabs {
    display: inline-flex;
    gap: 6px;
    padding: 5px;
    border: 1px solid var(--border-soft);
    border-radius: 14px;
    background: var(--card-bg);
}

.activity-tabs a {
    padding: 9px 13px;
    border-radius: 10px;
    color: var(--text-muted);
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
}

.activity-tabs a.active {
    color: #fff;
    background: var(--brand);
}

.activity-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.activity-stat {
    padding: 16px;
    border: 1px solid var(--border-soft);
    border-radius: 16px;
    background: var(--card-bg);
    box-shadow: var(--shadow);
}

.activity-stat small {
    display: block;
    color: var(--text-muted);
    font-weight: 750;
    margin-bottom: 6px;
}

.activity-stat strong {
    display: block;
    font-size: 21px;
    font-weight: 900;
}

.activity-table {
    min-width: 1120px;
}

.activity-user {
    font-weight: 850;
}

.activity-meta {
    display: block;
    margin-top: 2px;
    color: var(--text-muted);
    font-size: 11px;
}

.log-action {
    display: inline-flex;
    padding: 6px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 850;
    white-space: nowrap;
}

.log-action-create {
    color: #15803d;
    background: #dcfce7;
}

.log-action-update {
    color: #1d4ed8;
    background: #dbeafe;
}

.log-action-danger {
    color: #b91c1c;
    background: #fee2e2;
}

.log-action-logout {
    color: #7c3aed;
    background: #ede9fe;
}

.log-action-neutral {
    color: #475569;
    background: #f1f5f9;
}

.activity-description {
    max-width: 380px;
    white-space: normal;
}

.activity-mobile-list {
    display: none;
}

.activity-mobile-card {
    padding: 15px;
    border: 1px solid var(--border-soft);
    border-radius: 16px;
    background: var(--card-bg);
}

.activity-mobile-card + .activity-mobile-card {
    margin-top: 12px;
}

.activity-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding-top: 16px;
}

.activity-json {
    margin: 0;
    max-height: 280px;
    overflow: auto;
    padding: 12px;
    border-radius: 12px;
    color: #e2e8f0;
    background: #0f172a;
    font-size: 12px;
    white-space: pre-wrap;
    word-break: break-word;
}

@media (max-width: 1100px) {
    .activity-filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .activity-filter-bottom {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .activity-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 767.98px) {
    .activity-filter-grid,
    .activity-filter-bottom,
    .activity-stats {
        grid-template-columns: 1fr;
    }

    .activity-filter-bottom .btn {
        width: 100%;
    }

    .activity-desktop-table {
        display: none;
    }

    .activity-mobile-list {
        display: block;
    }

    .activity-pagination {
        align-items: stretch;
        flex-direction: column;
    }

    .activity-pagination .pagination {
        justify-content: center;
        flex-wrap: wrap;
    }
}
</style>

<div class="page-head">
    <div>
        <span class="badge-soft">ADMINISTRATION</span>
        <h1 class="mt-2">Activity Logs</h1>
        <p>
            Audit business changes, invoice actions and account login events.
        </p>
    </div>

    <div class="activity-tabs">
        <a
            class="<?= $logType === 'business' ? 'active' : '' ?>"
            href="<?= e(app_url('activity-logs.php?log_type=business')) ?>"
        >
            Business Activity
        </a>
        <a
            class="<?= $logType === 'login' ? 'active' : '' ?>"
            href="<?= e(app_url('activity-logs.php?log_type=login')) ?>"
        >
            Login Security
        </a>
    </div>
</div>

<section class="card-ui p-3 p-lg-4 mb-3">
    <form method="get">
        <input
            type="hidden"
            name="log_type"
            value="<?= e($logType) ?>"
        >

        <div class="activity-filter-grid">
            <div>
                <label class="form-label fw-semibold">Search</label>
                <div class="input-group">
                    <span class="input-group-text">
                        <i data-lucide="search"></i>
                    </span>
                    <input
                        class="form-control"
                        type="search"
                        name="q"
                        value="<?= e($search) ?>"
                        placeholder="Description, user, module, entity or IP..."
                    >
                </div>
            </div>

            <?php if ($logType === 'business'): ?>
                <div>
                    <label class="form-label fw-semibold">Module</label>
                    <select class="form-select" name="module_key">
                        <option value="">All Modules</option>
                        <?php foreach ($modules as $module): ?>
                            <option
                                value="<?= e($module) ?>"
                                <?= $moduleKey === $module ? 'selected' : '' ?>
                            >
                                <?= e($pretty($module)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div>
                <label class="form-label fw-semibold">
                    <?= $logType === 'business' ? 'Action' : 'Login Event' ?>
                </label>
                <select class="form-select" name="action_type">
                    <option value="">All Actions</option>

                    <?php if ($logType === 'business'): ?>
                        <?php foreach ($actions as $action): ?>
                            <option
                                value="<?= e($action) ?>"
                                <?= $actionType === $action ? 'selected' : '' ?>
                            >
                                <?= e($pretty($action)) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ([
                            'login_success',
                            'login_failed',
                            'logout',
                            'password_changed',
                        ] as $event): ?>
                            <option
                                value="<?= e($event) ?>"
                                <?= $actionType === $event ? 'selected' : '' ?>
                            >
                                <?= e($pretty($event)) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div>
                <label class="form-label fw-semibold">User</label>
                <select class="form-select" name="user_id">
                    <option value="0">All Users</option>
                    <?php foreach ($users as $user): ?>
                        <option
                            value="<?= (int)$user['id'] ?>"
                            <?= $userId === (int)$user['id'] ? 'selected' : '' ?>
                        >
                            <?= e(
                                $user['full_name'] .
                                ' (' . $user['username'] . ')'
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="activity-filter-bottom">
            <div>
                <label class="form-label fw-semibold">From Date</label>
                <input
                    class="form-control"
                    type="date"
                    name="date_from"
                    value="<?= e($dateFrom) ?>"
                >
            </div>

            <div>
                <label class="form-label fw-semibold">To Date</label>
                <input
                    class="form-control"
                    type="date"
                    name="date_to"
                    value="<?= e($dateTo) ?>"
                >
            </div>

            <div>
                <label class="form-label fw-semibold">Rows</label>
                <select class="form-select" name="per_page">
                    <?php foreach ([10, 25, 50, 100] as $size): ?>
                        <option
                            value="<?= $size ?>"
                            <?= $perPage === $size ? 'selected' : '' ?>
                        >
                            <?= $size ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button class="btn btn-brand" type="submit">
                <i data-lucide="list-filter"></i>
                Apply
            </button>

            <a
                class="btn btn-light"
                href="<?= e(
                    app_url(
                        'activity-logs.php?log_type=' . $logType
                    )
                ) ?>"
            >
                <i data-lucide="rotate-ccw"></i>
                Reset
            </a>
        </div>
    </form>
</section>

<div class="activity-stats">
    <article class="activity-stat">
        <small>Today’s Activities</small>
        <strong><?= number_format((int)($summary['total_count'] ?? 0)) ?></strong>
    </article>

    <article class="activity-stat">
        <small>
            <?= $logType === 'business' ? 'Created' : 'Successful Logins' ?>
        </small>
        <strong><?= number_format((int)($summary['create_count'] ?? 0)) ?></strong>
    </article>

    <article class="activity-stat">
        <small>
            <?= $logType === 'business' ? 'Updated' : 'Failed Logins' ?>
        </small>
        <strong><?= number_format((int)($summary['update_count'] ?? 0)) ?></strong>
    </article>

    <article class="activity-stat">
        <small>
            <?= $logType === 'business' ? 'Delete / Cancel / Reverse' : 'Logouts' ?>
        </small>
        <strong><?= number_format((int)($summary['destructive_count'] ?? 0)) ?></strong>
    </article>
</div>

<section class="card-ui p-3">
    <div class="activity-desktop-table table-responsive">
        <table class="table align-middle mb-0 activity-table">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>User</th>
                    <th><?= $logType === 'business' ? 'Module' : 'Username Entered' ?></th>
                    <th>Action</th>
                    <?php if ($logType === 'business'): ?>
                        <th>Entity</th>
                        <th>Description</th>
                    <?php endif; ?>
                    <th>IP Address</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$logs): ?>
                    <tr>
                        <td
                            colspan="<?= $logType === 'business' ? 8 : 6 ?>"
                            class="text-center text-muted py-5"
                        >
                            <i data-lucide="history"></i>
                            <div class="fw-bold mt-2">No activity logs found</div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($logs as $log): ?>
                    <?php
                    $eventTime = $logType === 'business'
                        ? $log['created_at']
                        : $log['event_at'];

                    $eventAction = $logType === 'business'
                        ? $log['action_type']
                        : $log['event_type'];

                    $details = [
                        'type' => $logType,
                        'id' => (int)$log['id'],
                        'user' => $log['full_name'] ?: ($log['username'] ?? 'System'),
                        'username' => $log['username'] ?? null,
                        'role' => $log['role_key'] ?? null,
                        'action' => $eventAction,
                        'ip_address' => $log['ip_address'] ?? null,
                        'user_agent' => $log['user_agent'] ?? null,
                        'date_time' => $eventTime,
                    ];

                    if ($logType === 'business') {
                        $details['module'] = $log['module_key'];
                        $details['entity_type'] = $log['entity_type'];
                        $details['entity_id'] = $log['entity_id'];
                        $details['description'] = $log['description'];
                        $details['old_values'] = $log['old_values_json']
                            ? json_decode($log['old_values_json'], true)
                            : null;
                        $details['new_values'] = $log['new_values_json']
                            ? json_decode($log['new_values_json'], true)
                            : null;
                    } else {
                        $details['username_entered'] =
                            $log['username_entered'];
                    }
                    ?>

                    <tr>
                        <td>
                            <strong>
                                <?= e(date('d-m-Y', strtotime($eventTime))) ?>
                            </strong>
                            <small class="activity-meta">
                                <?= e(date('h:i:s A', strtotime($eventTime))) ?>
                            </small>
                        </td>

                        <td>
                            <span class="activity-user">
                                <?= e(
                                    $log['full_name']
                                    ?: ($log['username'] ?? 'System / Unknown')
                                ) ?>
                            </span>
                            <small class="activity-meta">
                                <?= e($log['username'] ?? '—') ?>
                            </small>
                        </td>

                        <td>
                            <?= e(
                                $logType === 'business'
                                    ? $pretty($log['module_key'])
                                    : ($log['username_entered'] ?: '—')
                            ) ?>
                        </td>

                        <td>
                            <span class="log-action <?= e($actionClass($eventAction)) ?>">
                                <?= e($pretty($eventAction)) ?>
                            </span>
                        </td>

                        <?php if ($logType === 'business'): ?>
                            <td>
                                <?= e($pretty((string)($log['entity_type'] ?? ''))) ?>
                                <?php if ($log['entity_id']): ?>
                                    <small class="activity-meta">
                                        ID: <?= (int)$log['entity_id'] ?>
                                    </small>
                                <?php endif; ?>
                            </td>

                            <td class="activity-description">
                                <?= e($log['description'] ?: '—') ?>
                            </td>
                        <?php endif; ?>

                        <td><?= e($log['ip_address'] ?: '—') ?></td>

                        <td>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="activity-mobile-list">
        <?php foreach ($logs as $log): ?>
            <?php
            $eventTime = $logType === 'business'
                ? $log['created_at']
                : $log['event_at'];

            $eventAction = $logType === 'business'
                ? $log['action_type']
                : $log['event_type'];

            $details = [
                'type' => $logType,
                'id' => (int)$log['id'],
                'user' => $log['full_name'] ?: ($log['username'] ?? 'System'),
                'username' => $log['username'] ?? null,
                'role' => $log['role_key'] ?? null,
                'action' => $eventAction,
                'ip_address' => $log['ip_address'] ?? null,
                'user_agent' => $log['user_agent'] ?? null,
                'date_time' => $eventTime,
            ];

            if ($logType === 'business') {
                $details['module'] = $log['module_key'];
                $details['entity_type'] = $log['entity_type'];
                $details['entity_id'] = $log['entity_id'];
                $details['description'] = $log['description'];
                $details['old_values'] = $log['old_values_json']
                    ? json_decode($log['old_values_json'], true)
                    : null;
                $details['new_values'] = $log['new_values_json']
                    ? json_decode($log['new_values_json'], true)
                    : null;
            } else {
                $details['username_entered'] = $log['username_entered'];
            }
            ?>

            <article class="activity-mobile-card">
                <div class="d-flex justify-content-between gap-2">
                    <div>
                        <strong>
                            <?= e(
                                $log['full_name']
                                ?: ($log['username'] ?? 'System / Unknown')
                            ) ?>
                        </strong>
                        <small class="activity-meta">
                            <?= e(date('d-m-Y h:i A', strtotime($eventTime))) ?>
                        </small>
                    </div>

                    <span class="log-action <?= e($actionClass($eventAction)) ?>">
                        <?= e($pretty($eventAction)) ?>
                    </span>
                </div>

                <?php if ($logType === 'business'): ?>
                    <div class="mt-3">
                        <strong><?= e($pretty($log['module_key'])) ?></strong>
                        <p class="small text-muted mb-0 mt-1">
                            <?= e($log['description'] ?: '—') ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="mt-3 small">
                        Username entered:
                        <strong><?= e($log['username_entered'] ?: '—') ?></strong>
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mt-3">
                    <small class="text-muted">
                        IP: <?= e($log['ip_address'] ?: '—') ?>
                    </small>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($totalRows > 0): ?>
        <div class="activity-pagination">
            <small class="text-muted">
                Showing
                <?= number_format($offset + 1) ?>
                to
                <?= number_format(min($offset + $perPage, $totalRows)) ?>
                of
                <?= number_format($totalRows) ?>
                logs
            </small>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Activity log pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a
                                class="page-link"
                                href="<?= e(
                                    $buildPageUrl(
                                        max(1, $page - 1),
                                        $queryWithoutPage
                                    )
                                ) ?>"
                            >
                                Previous
                            </a>
                        </li>

                        <?php
                        $pageStart = max(1, $page - 2);
                        $pageEnd = min($totalPages, $page + 2);
                        ?>

                        <?php for (
                            $pageNumber = $pageStart;
                            $pageNumber <= $pageEnd;
                            $pageNumber++
                        ): ?>
                            <li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>">
                                <a
                                    class="page-link"
                                    href="<?= e(
                                        $buildPageUrl(
                                            $pageNumber,
                                            $queryWithoutPage
                                        )
                                    ) ?>"
                                >
                                    <?= $pageNumber ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a
                                class="page-link"
                                href="<?= e(
                                    $buildPageUrl(
                                        min($totalPages, $page + 1),
                                        $queryWithoutPage
                                    )
                                ) ?>"
                            >
                                Next
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>


<script>
if (window.lucide) { lucide.createIcons(); }
</script>

<?php include __DIR__ . '/includes/layout-end.php'; ?>
