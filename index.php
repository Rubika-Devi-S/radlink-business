<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';

require_login();

$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT
        b.id,
        b.business_name,
        b.business_code,
        b.business_type_id,
        b.logo_path,
        b.status,
        uba.is_default
     FROM user_business_access uba
     INNER JOIN businesses b ON b.id = uba.business_id
     WHERE uba.user_id = :user_id
       AND uba.status = 'active'
       AND b.status = 'active'
     ORDER BY uba.is_default DESC, b.business_name ASC"
);
$stmt->execute(['user_id' => $userId]);
$businesses = $stmt->fetchAll();

$currentBusinessId = isset($_GET['business_id']) ? (int) $_GET['business_id'] : 0;

if ($currentBusinessId > 0) {
    $allowed = false;
    foreach ($businesses as $business) {
        if ((int) $business['id'] === $currentBusinessId) {
            $allowed = true;
            break;
        }
    }

    if ($allowed) {
        $_SESSION['business_id'] = $currentBusinessId;
    }
}

if (empty($_SESSION['business_id']) && !empty($businesses)) {
    $_SESSION['business_id'] = (int) $businesses[0]['id'];
}

$currentBusiness = null;
foreach ($businesses as $business) {
    if ((int) $business['id'] === (int) ($_SESSION['business_id'] ?? 0)) {
        $currentBusiness = $business;
        break;
    }
}

$dashboard = [
    'clients' => 0,
    'invoices_today' => 0,
    'billing_today' => 0.00,
    'received_today' => 0.00,
    'outstanding' => 0.00,
];

$recentInvoices = [];

if ($currentBusiness) {
    $businessId = (int) $currentBusiness['id'];

    $q = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE business_id = :business_id AND status = 'active'");
    $q->execute(['business_id' => $businessId]);
    $dashboard['clients'] = (int) $q->fetchColumn();

    $q = $pdo->prepare(
        "SELECT COUNT(*), COALESCE(SUM(grand_total), 0)
         FROM invoices
         WHERE business_id = :business_id
           AND invoice_date = CURDATE()
           AND invoice_status = 'issued'"
    );
    $q->execute(['business_id' => $businessId]);
    [$dashboard['invoices_today'], $dashboard['billing_today']] = $q->fetch(PDO::FETCH_NUM);

    $q = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0)
         FROM payments
         WHERE business_id = :business_id
           AND payment_date = CURDATE()
           AND payment_status = 'posted'"
    );
    $q->execute(['business_id' => $businessId]);
    $dashboard['received_today'] = (float) $q->fetchColumn();

    $q = $pdo->prepare(
        "SELECT COALESCE(SUM(calculated_balance), 0)
         FROM vw_invoice_balances
         WHERE business_id = :business_id
           AND invoice_status = 'issued'"
    );
    $q->execute(['business_id' => $businessId]);
    $dashboard['outstanding'] = (float) $q->fetchColumn();

    $q = $pdo->prepare(
        "SELECT
            i.invoice_number,
            i.invoice_date,
            c.client_name,
            i.grand_total,
            i.payment_status
         FROM invoices i
         INNER JOIN clients c ON c.id = i.client_id
         WHERE i.business_id = :business_id
         ORDER BY i.id DESC
         LIMIT 5"
    );
    $q->execute(['business_id' => $businessId]);
    $recentInvoices = $q->fetchAll();
}

function money(float|int|string $amount): string
{
    return '₹' . number_format((float) $amount, 2);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | RAD LINK HEALTH</title>
    <meta name="theme-color" content="#6f42c1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="dashboard-page">
    <nav class="navbar navbar-expand-lg app-navbar sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <span class="brand-icon"><i class="bi bi-heart-pulse-fill"></i></span>
                <span>
                    <strong>RAD LINK HEALTH</strong>
                    <small>Business Suite</small>
                </span>
            </a>

            <div class="d-flex align-items-center gap-2 ms-auto">
                <div class="dropdown">
                    <button class="btn btn-light dropdown-toggle business-switcher"
                            data-bs-toggle="dropdown" type="button">
                        <i class="bi bi-buildings"></i>
                        <span class="d-none d-sm-inline">
                            <?= htmlspecialchars($currentBusiness['business_name'] ?? 'No Business', ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <?php foreach ($businesses as $business): ?>
                            <li>
                                <a class="dropdown-item <?= (int)($currentBusiness['id'] ?? 0) === (int)$business['id'] ? 'active' : '' ?>"
                                   href="?business_id=<?= (int)$business['id'] ?>">
                                    <?= htmlspecialchars($business['business_name'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="dropdown">
                    <button class="btn profile-btn" data-bs-toggle="dropdown" type="button">
                        <span class="avatar">
                            <?= htmlspecialchars(strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li class="px-3 py-2">
                            <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'User', ENT_QUOTES, 'UTF-8') ?></strong>
                            <small class="d-block text-muted">
                                <?= htmlspecialchars($_SESSION['role_key'] ?? 'user', ENT_QUOTES, 'UTF-8') ?>
                            </small>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="logout.php">
                                <i class="bi bi-box-arrow-right me-2"></i>Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid app-shell">
        <aside class="sidebar d-none d-lg-block">
            <nav class="nav flex-column">
                <a class="nav-link active" href="index.php"><i class="bi bi-grid"></i>Dashboard</a>
                <a class="nav-link" href="#"><i class="bi bi-hospital"></i>Clients / Hospitals</a>
                <a class="nav-link" href="#"><i class="bi bi-activity"></i>Services</a>
                <a class="nav-link" href="#"><i class="bi bi-receipt"></i>Invoices</a>
                <a class="nav-link" href="#"><i class="bi bi-credit-card"></i>Payments</a>
                <a class="nav-link" href="#"><i class="bi bi-arrow-left-right"></i>Settlements</a>
                <a class="nav-link" href="#"><i class="bi bi-wallet2"></i>Expenses</a>
                <a class="nav-link" href="#"><i class="bi bi-bar-chart"></i>Reports</a>
                <a class="nav-link" href="#"><i class="bi bi-gear"></i>Settings</a>
            </nav>
        </aside>

        <main class="main-content">
            <div class="page-header">
                <div>
                    <span class="eyebrow">OVERVIEW</span>
                    <h1>Dashboard</h1>
                    <p>
                        Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? 'User', ENT_QUOTES, 'UTF-8') ?>.
                    </p>
                </div>
                <button class="btn btn-primary quick-action">
                    <i class="bi bi-plus-lg"></i>
                    New Invoice
                </button>
            </div>

            <?php if (!$currentBusiness): ?>
                <div class="alert alert-warning">
                    No active business is assigned to this account.
                </div>
            <?php else: ?>
                <div class="row g-3 g-xl-4">
                    <div class="col-6 col-xl">
                        <div class="metric-card">
                            <div class="metric-icon lavender"><i class="bi bi-hospital"></i></div>
                            <span>Active Clients</span>
                            <strong><?= number_format($dashboard['clients']) ?></strong>
                        </div>
                    </div>

                    <div class="col-6 col-xl">
                        <div class="metric-card">
                            <div class="metric-icon blue"><i class="bi bi-receipt"></i></div>
                            <span>Today’s Invoices</span>
                            <strong><?= number_format((int) $dashboard['invoices_today']) ?></strong>
                        </div>
                    </div>

                    <div class="col-6 col-xl">
                        <div class="metric-card">
                            <div class="metric-icon cyan"><i class="bi bi-currency-rupee"></i></div>
                            <span>Today’s Billing</span>
                            <strong><?= money($dashboard['billing_today']) ?></strong>
                        </div>
                    </div>

                    <div class="col-6 col-xl">
                        <div class="metric-card">
                            <div class="metric-icon green"><i class="bi bi-wallet2"></i></div>
                            <span>Today’s Received</span>
                            <strong><?= money($dashboard['received_today']) ?></strong>
                        </div>
                    </div>

                    <div class="col-12 col-xl">
                        <div class="metric-card">
                            <div class="metric-icon orange"><i class="bi bi-hourglass-split"></i></div>
                            <span>Total Outstanding</span>
                            <strong><?= money($dashboard['outstanding']) ?></strong>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mt-1">
                    <div class="col-xl-8">
                        <div class="content-card">
                            <div class="card-heading">
                                <div>
                                    <h2>Recent Invoices</h2>
                                    <p>Latest billing activity for the selected business.</p>
                                </div>
                                <a href="#" class="btn btn-sm btn-light">View all</a>
                            </div>

                            <?php if (empty($recentInvoices)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-receipt-cutoff"></i>
                                    <h3>No invoices yet</h3>
                                    <p>Create your first invoice to see it here.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Invoice</th>
                                                <th>Client</th>
                                                <th>Date</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($recentInvoices as $invoice): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                                <td><?= htmlspecialchars($invoice['client_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= date('d M Y', strtotime($invoice['invoice_date'])) ?></td>
                                                <td><?= money($invoice['grand_total']) ?></td>
                                                <td>
                                                    <span class="status-badge status-<?= htmlspecialchars($invoice['payment_status'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $invoice['payment_status'])), ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-xl-4">
                        <div class="content-card h-100">
                            <div class="card-heading">
                                <div>
                                    <h2>Quick Actions</h2>
                                    <p>Frequently used operations.</p>
                                </div>
                            </div>

                            <div class="quick-actions-grid">
                                <a href="#" class="quick-action-card">
                                    <i class="bi bi-person-plus"></i>
                                    <span>Add Client</span>
                                </a>
                                <a href="#" class="quick-action-card">
                                    <i class="bi bi-plus-circle"></i>
                                    <span>Add Service</span>
                                </a>
                                <a href="#" class="quick-action-card">
                                    <i class="bi bi-receipt"></i>
                                    <span>Create Invoice</span>
                                </a>
                                <a href="#" class="quick-action-card">
                                    <i class="bi bi-cash-coin"></i>
                                    <span>Record Payment</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <nav class="mobile-bottom-nav d-lg-none">
        <a class="active" href="index.php"><i class="bi bi-house"></i><span>Home</span></a>
        <a href="#"><i class="bi bi-hospital"></i><span>Clients</span></a>
        <a class="center-action" href="#"><i class="bi bi-plus-lg"></i><span>Invoice</span></a>
        <a href="#"><i class="bi bi-credit-card"></i><span>Payments</span></a>
        <a href="#"><i class="bi bi-grid-3x3-gap"></i><span>More</span></a>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
