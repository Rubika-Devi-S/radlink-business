<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';

redirect_if_logged_in();

$error = '';
$usernameOrEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameOrEmail = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $csrf = $_POST['csrf_token'] ?? null;

    if (!verify_csrf_token(is_string($csrf) ? $csrf : null)) {
        $error = 'Your session has expired. Please refresh the page and try again.';
    } elseif ($usernameOrEmail === '' || $password === '') {
        $error = 'Enter your username or email and password.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, full_name, username, email, password_hash, role_key, is_super_admin, status
             FROM users
             WHERE username = :login OR email = :login
             LIMIT 1"
        );
        $stmt->execute(['login' => $usernameOrEmail]);
        $user = $stmt->fetch();

        if (
            $user
            && $user['status'] === 'active'
            && password_verify($password, $user['password_hash'])
        ) {
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role_key'] = $user['role_key'];
            $_SESSION['is_super_admin'] = (bool) $user['is_super_admin'];
            $_SESSION['logged_in_at'] = date('Y-m-d H:i:s');

            $update = $pdo->prepare(
                "UPDATE users SET last_login_at = NOW() WHERE id = :id"
            );
            $update->execute(['id' => $user['id']]);

            $log = $pdo->prepare(
                "INSERT INTO login_activity_logs
                    (user_id, username_entered, event_type, ip_address, user_agent)
                 VALUES
                    (:user_id, :username_entered, 'login_success', :ip_address, :user_agent)"
            );
            $log->execute([
                'user_id' => $user['id'],
                'username_entered' => $usernameOrEmail,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);

            $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
            unset($_SESSION['redirect_after_login']);

            header('Location: ' . $redirect);
            exit;
        }

        $log = $pdo->prepare(
            "INSERT INTO login_activity_logs
                (user_id, username_entered, event_type, ip_address, user_agent)
             VALUES
                (NULL, :username_entered, 'login_failed', :ip_address, :user_agent)"
        );
        $log->execute([
            'username_entered' => $usernameOrEmail,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);

        $error = 'Invalid login details or inactive account.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | RAD LINK HEALTH</title>
    <meta name="theme-color" content="#6f42c1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="auth-page">
    <main class="container-fluid min-vh-100">
        <div class="row min-vh-100">
            <section class="col-lg-6 d-none d-lg-flex auth-visual">
                <div class="auth-visual-content">
                    <span class="brand-pill">
                        <i class="bi bi-heart-pulse-fill"></i>
                        RAD LINK BUSINESS SUITE
                    </span>

                    <h1>Manage RAD LINK HEALTH from one secure dashboard.</h1>

                    <p>
                        Client billing, reporting services, hospital settlements,
                        payments, expenses and business reports in one responsive system.
                    </p>

                    <div class="feature-grid">
                        <div class="feature-card">
                            <i class="bi bi-hospital"></i>
                            <span>Clients & Hospitals</span>
                        </div>
                        <div class="feature-card">
                            <i class="bi bi-receipt"></i>
                            <span>Invoices & Payments</span>
                        </div>
                        <div class="feature-card">
                            <i class="bi bi-arrow-left-right"></i>
                            <span>Settlements</span>
                        </div>
                        <div class="feature-card">
                            <i class="bi bi-bar-chart"></i>
                            <span>Business Reports</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="col-12 col-lg-6 d-flex align-items-center justify-content-center p-3 p-md-5">
                <div class="login-card">
                    <div class="mobile-brand d-lg-none">
                        <div class="mobile-logo">
                            <i class="bi bi-heart-pulse-fill"></i>
                        </div>
                        <div>
                            <strong>RAD LINK HEALTH</strong>
                            <small>Business Management System</small>
                        </div>
                    </div>

                    <div class="login-heading">
                        <span class="eyebrow">SECURE LOGIN</span>
                        <h2>Welcome back</h2>
                        <p>Sign in to continue to your dashboard.</p>
                    </div>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
                            <i class="bi bi-exclamation-circle-fill mt-1"></i>
                            <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="post" autocomplete="on" novalidate>
                        <input type="hidden" name="csrf_token"
                               value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

                        <div class="mb-3">
                            <label for="username" class="form-label">Username or email</label>
                            <div class="input-group input-group-lg custom-input">
                                <span class="input-group-text">
                                    <i class="bi bi-person"></i>
                                </span>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="username"
                                    name="username"
                                    value="<?= htmlspecialchars($usernameOrEmail, ENT_QUOTES, 'UTF-8') ?>"
                                    placeholder="Enter username or email"
                                    autocomplete="username"
                                    required
                                    autofocus
                                >
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group input-group-lg custom-input">
                                <span class="input-group-text">
                                    <i class="bi bi-lock"></i>
                                </span>
                                <input
                                    type="password"
                                    class="form-control"
                                    id="password"
                                    name="password"
                                    placeholder="Enter password"
                                    autocomplete="current-password"
                                    required
                                >
                                <button class="btn password-toggle" type="button" id="togglePassword"
                                        aria-label="Show or hide password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100 login-btn">
                            <span>Sign in</span>
                            <i class="bi bi-arrow-right"></i>
                        </button>
                    </form>

                    <div class="login-footer">
                        <i class="bi bi-shield-check"></i>
                        Secure access for authorised users only
                    </div>
                </div>
            </section>
        </div>
    </main>

    <script>
        const toggle = document.getElementById('togglePassword');
        const password = document.getElementById('password');

        toggle.addEventListener('click', function () {
            const show = password.type === 'password';
            password.type = show ? 'text' : 'password';
            this.innerHTML = show
                ? '<i class="bi bi-eye-slash"></i>'
                : '<i class="bi bi-eye"></i>';
        });
    </script>
</body>
</html>
