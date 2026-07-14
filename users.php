<?php
declare(strict_types=1);

$pageTitle = 'Users';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';

require_page_permission('users', 'view');

$canCreate = can_access('users', 'create');
$canEdit = can_access('users', 'edit');
$canDelete = can_access('users', 'delete');
$canApprove = can_access('users', 'approve');

$rolesStmt = $pdo->prepare(
    "SELECT role_key, role_name, description, is_system, status
     FROM business_roles
     WHERE business_id = ?
       AND status = 'active'
     ORDER BY is_system DESC, role_name"
);
$rolesStmt->execute([$currentBusinessId]);
$roles = $rolesStmt->fetchAll();

$usersStmt = $pdo->prepare(
    "SELECT
        u.id,
        u.full_name,
        u.username,
        u.email,
        u.mobile,
        u.status,
        u.is_super_admin,
        u.last_login_at,
        u.created_at,
        uba.access_role,
        uba.is_default,
        uba.status AS access_status,
        br.role_name
     FROM user_business_access uba
     INNER JOIN users u
        ON u.id = uba.user_id
     LEFT JOIN business_roles br
        ON br.business_id = uba.business_id
       AND br.role_key = uba.access_role
     WHERE uba.business_id = ?
     ORDER BY u.id DESC"
);
$usersStmt->execute([$currentBusinessId]);
$users = $usersStmt->fetchAll();

$stats = [
    'total' => count($users),
    'active' => 0,
    'inactive' => 0,
    'blocked' => 0,
];

foreach ($users as $user) {
    $status = (string)$user['status'];
    if (isset($stats[$status])) {
        $stats[$status]++;
    }
}

include __DIR__ . '/includes/layout-start.php';
?>
<style>
.user-admin-page .page-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}
.user-admin-page .page-heading h1{margin:8px 0 4px;font-weight:850}
.user-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}
.user-kpi{display:flex;align-items:center;gap:14px;padding:18px;border:1px solid var(--border-soft);border-radius:18px;background:var(--card-bg);box-shadow:var(--shadow)}
.user-kpi-icon{width:48px;height:48px;min-width:48px;display:grid;place-items:center;border-radius:15px;background:var(--sidebar-active);color:var(--brand)}
.user-kpi small{display:block;color:var(--text-muted);font-weight:700}
.user-kpi strong{display:block;font-size:22px;margin-top:3px}
.user-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:18px;box-shadow:var(--shadow)}
.user-card-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:18px 20px;border-bottom:1px solid var(--border-soft)}
.user-card-body{padding:18px}
.user-avatar{width:42px;height:42px;min-width:42px;display:grid;place-items:center;border-radius:14px;background:var(--sidebar-active);color:var(--brand);font-weight:900}
.user-name-cell{display:flex;align-items:center;gap:10px}
.user-status{display:inline-flex;padding:6px 9px;border-radius:999px;font-size:11px;font-weight:800}
.user-status.active{background:rgba(25,135,84,.12);color:#198754}
.user-status.inactive{background:rgba(255,193,7,.15);color:#8a6500}
.user-status.blocked{background:rgba(220,53,69,.12);color:#dc3545}
.password-wrap{position:relative}
.password-wrap .form-control{padding-right:44px}
.password-toggle{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:var(--text-muted);width:34px;height:34px;border-radius:10px}
.password-note{padding:12px;border:1px solid rgba(255,193,7,.35);border-radius:13px;background:rgba(255,193,7,.08)}
.mobile-users{display:none}
@media(max-width:991.98px){.user-kpis{grid-template-columns:repeat(2,1fr)}}
@media(max-width:767.98px){.user-admin-page .page-heading{flex-direction:column}.user-admin-page .page-heading .btn{width:100%}.user-kpis{grid-template-columns:1fr 1fr}.desktop-users{display:none}.mobile-users{display:block}.mobile-user-card{padding:15px;margin-bottom:10px}}
@media(max-width:480px){.user-kpis{grid-template-columns:1fr}}
</style>

<div class="user-admin-page">
    <div class="page-heading">
        <div>
            <span class="badge-soft">ACCESS CONTROL</span>
            <h1>Users</h1>
            <p class="mb-0 text-muted">Create login users and assign a business role.</p>
        </div>
        <?php if ($canCreate): ?>
            <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#userModal" id="addUserButton">
                <i data-lucide="user-plus"></i> Add User
            </button>
        <?php endif; ?>
    </div>

    <div class="user-kpis">
        <div class="user-kpi"><div class="user-kpi-icon"><i data-lucide="users"></i></div><div><small>Total Users</small><strong><?= (int)$stats['total'] ?></strong></div></div>
        <div class="user-kpi"><div class="user-kpi-icon"><i data-lucide="circle-check-big"></i></div><div><small>Active</small><strong><?= (int)$stats['active'] ?></strong></div></div>
        <div class="user-kpi"><div class="user-kpi-icon"><i data-lucide="circle-pause"></i></div><div><small>Inactive</small><strong><?= (int)$stats['inactive'] ?></strong></div></div>
        <div class="user-kpi"><div class="user-kpi-icon"><i data-lucide="shield-x"></i></div><div><small>Blocked</small><strong><?= (int)$stats['blocked'] ?></strong></div></div>
    </div>

    <section class="user-card">
        <div class="user-card-head">
            <div><h2 class="h6 fw-bold mb-1">Business Users</h2><small class="text-muted">Manage login status, role assignment and password reset.</small></div>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(app_url('role-permissions.php')) ?>"><i data-lucide="shield-check"></i> Role Permissions</a>
        </div>
        <div class="user-card-body">
            <div class="desktop-users table-responsive">
                <table class="table align-middle" id="usersTable">
                    <thead><tr><th>User</th><th>Contact</th><th>Role</th><th>Access</th><th>Last Login</th><th>Created</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php if (!$users): ?>
                        <tr><td colspan="7" class="text-center text-muted py-5">No users found for this business.</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <div class="user-name-cell">
                                        <div class="user-avatar"><?= e(strtoupper(substr((string)$user['full_name'], 0, 1))) ?></div>
                                        <div><strong><?= e($user['full_name']) ?></strong><small class="d-block text-muted"><?= e($user['username']) ?></small></div>
                                    </div>
                                </td>
                                <td><?= e($user['email'] ?: '—') ?><small class="d-block text-muted"><?= e($user['mobile'] ?: '') ?></small></td>
                                <td><strong><?= e($user['role_name'] ?: ucwords(str_replace('_', ' ', $user['access_role']))) ?></strong><small class="d-block text-muted"><?= e($user['access_role']) ?></small></td>
                                <td><span class="user-status <?= e($user['status']) ?>"><?= e(ucfirst($user['status'])) ?></span></td>
                                <td><?= $user['last_login_at'] ? e(date('d M Y, h:i A', strtotime($user['last_login_at']))) : 'Never' ?></td>
                                <td><?= e(date('d M Y', strtotime($user['created_at']))) ?></td>
                                <td class="text-end">
                                    <?php if ($canEdit): ?>
                                        <button class="btn btn-sm btn-outline-primary edit-user" type="button" data-user='<?= e(json_encode($user, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'><i data-lucide="pencil"></i></button>
                                    <?php endif; ?>
                                    <?php if ($canApprove && (int)$user['id'] !== current_user_id()): ?>
                                        <button class="btn btn-sm btn-outline-warning toggle-user" type="button" data-id="<?= (int)$user['id'] ?>"><i data-lucide="power"></i></button>
                                    <?php endif; ?>
                                    <?php if ($canDelete && (int)$user['id'] !== current_user_id()): ?>
                                        <button class="btn btn-sm btn-outline-danger remove-access" type="button" data-id="<?= (int)$user['id'] ?>"><i data-lucide="user-minus"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-users">
                <?php foreach ($users as $user): ?>
                    <article class="user-card mobile-user-card">
                        <div class="d-flex justify-content-between gap-3">
                            <div class="user-name-cell"><div class="user-avatar"><?= e(strtoupper(substr((string)$user['full_name'],0,1))) ?></div><div><strong><?= e($user['full_name']) ?></strong><small class="d-block text-muted"><?= e($user['username']) ?></small></div></div>
                            <span class="user-status <?= e($user['status']) ?>"><?= e(ucfirst($user['status'])) ?></span>
                        </div>
                        <div class="row g-2 mt-2 small">
                            <div class="col-6">Role<br><strong><?= e($user['role_name'] ?: $user['access_role']) ?></strong></div>
                            <div class="col-6">Last Login<br><strong><?= $user['last_login_at'] ? e(date('d M Y',strtotime($user['last_login_at']))) : 'Never' ?></strong></div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <?php if ($canEdit): ?><button class="btn btn-sm btn-outline-primary flex-fill edit-user" type="button" data-user='<?= e(json_encode($user, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button><?php endif; ?>
                            <?php if ($canApprove && (int)$user['id'] !== current_user_id()): ?><button class="btn btn-sm btn-outline-warning flex-fill toggle-user" type="button" data-id="<?= (int)$user['id'] ?>">Toggle</button><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</div>

<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content card-ui">
            <form id="userForm">
                <div class="modal-header">
                    <div><h5 class="modal-title" id="userModalTitle">Add User</h5><small class="text-muted">Create a login and assign access for the current business.</small></div>
                    <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_user">
                    <input type="hidden" name="user_id" id="userId" value="0">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label><input class="form-control" name="full_name" id="fullName" maxlength="150" required></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Username <span class="text-danger">*</span></label><input class="form-control" name="username" id="username" maxlength="100" required></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Email</label><input class="form-control" type="email" name="email" id="email" maxlength="190"></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Mobile</label><input class="form-control" name="mobile" id="mobile" maxlength="20"></div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Password <span class="text-danger" id="passwordRequired">*</span></label>
                            <div class="password-wrap"><input class="form-control" type="password" name="password" id="password" minlength="8"><button class="password-toggle" type="button" data-password-toggle="password"><i data-lucide="eye"></i></button></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Confirm Password <span class="text-danger" id="confirmRequired">*</span></label>
                            <div class="password-wrap"><input class="form-control" type="password" name="confirm_password" id="confirmPassword" minlength="8"><button class="password-toggle" type="button" data-password-toggle="confirmPassword"><i data-lucide="eye"></i></button></div>
                        </div>
                        <div class="col-12"><button class="btn btn-sm btn-outline-secondary" type="button" id="generatePassword"><i data-lucide="key-round"></i> Generate Password</button></div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Business Role <span class="text-danger">*</span></label>
                            <select class="form-select" name="access_role" id="accessRole" required>
                                <option value="">Select role...</option>
                                <?php foreach ($roles as $role): ?><option value="<?= e($role['role_key']) ?>"><?= e($role['role_name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Status</label><select class="form-select" name="status" id="userStatus"><option value="active">Active</option><option value="inactive">Inactive</option><option value="blocked">Blocked</option></select></div>
                        <div class="col-12"><div class="password-note small"><strong>Password rule:</strong> Minimum 8 characters. During edit, leave both password fields empty to keep the existing password.</div></div>
                    </div>
                </div>
                <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit" id="saveUserButton"><i data-lucide="save"></i> Save User</button></div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modalElement = document.getElementById('userModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const form = document.getElementById('userForm');

    function resetForm() {
        form.reset();
        document.getElementById('userId').value = '0';
        document.getElementById('userModalTitle').textContent = 'Add User';
        document.getElementById('password').required = true;
        document.getElementById('confirmPassword').required = true;
        document.getElementById('passwordRequired').classList.remove('d-none');
        document.getElementById('confirmRequired').classList.remove('d-none');
    }

    document.getElementById('addUserButton')?.addEventListener('click', resetForm);

    document.querySelectorAll('.edit-user').forEach(button => {
        button.addEventListener('click', () => {
            const user = JSON.parse(button.dataset.user);
            resetForm();
            document.getElementById('userModalTitle').textContent = 'Edit User';
            document.getElementById('userId').value = user.id;
            document.getElementById('fullName').value = user.full_name || '';
            document.getElementById('username').value = user.username || '';
            document.getElementById('email').value = user.email || '';
            document.getElementById('mobile').value = user.mobile || '';
            document.getElementById('accessRole').value = user.access_role || '';
            document.getElementById('userStatus').value = user.status || 'active';
            document.getElementById('password').required = false;
            document.getElementById('confirmPassword').required = false;
            document.getElementById('passwordRequired').classList.add('d-none');
            document.getElementById('confirmRequired').classList.add('d-none');
            modal.show();
        });
    });

    document.querySelectorAll('[data-password-toggle]').forEach(button => button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        input.type = input.type === 'password' ? 'text' : 'password';
        button.querySelector('i')?.setAttribute('data-lucide', input.type === 'password' ? 'eye' : 'eye-off');
        if (window.lucide) lucide.createIcons();
    }));

    document.getElementById('generatePassword').addEventListener('click', () => {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#$%';
        let password = '';
        crypto.getRandomValues(new Uint32Array(12)).forEach(value => password += chars[value % chars.length]);
        document.getElementById('password').value = password;
        document.getElementById('confirmPassword').value = password;
        document.getElementById('password').type = 'text';
        document.getElementById('confirmPassword').type = 'text';
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = document.getElementById('saveUserButton');
        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
        try {
            const response = await fetch('<?= e(app_url('api/users.php')) ?>', {method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
            const raw = await response.text();
            let result; try { result = JSON.parse(raw); } catch { throw new Error(raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim() || 'Invalid server response.'); }
            AppToast.show(result.success ? 'success' : 'error', result.message);
            if (result.success) setTimeout(() => location.reload(), 450);
        } catch (error) { AppToast.show('error', error.message || 'Unable to save user.'); }
        finally { button.disabled=false;button.innerHTML=original;if(window.lucide)lucide.createIcons(); }
    });

    async function userAction(action, userId, confirmation) {
        if (!confirm(confirmation)) return;
        const fd = new FormData();
        fd.set('csrf_token','<?= e(csrf_token()) ?>');
        fd.set('action',action);
        fd.set('user_id',userId);
        try {
            const response=await fetch('<?= e(app_url('api/users.php')) ?>',{method:'POST',body:fd,credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
            const result=await response.json();AppToast.show(result.success?'success':'error',result.message);if(result.success)setTimeout(()=>location.reload(),450);
        } catch(error){AppToast.show('error','Unable to update user.');}
    }

    document.querySelectorAll('.toggle-user').forEach(button=>button.addEventListener('click',()=>userAction('toggle_status',button.dataset.id,'Change this user status?')));
    document.querySelectorAll('.remove-access').forEach(button=>button.addEventListener('click',()=>userAction('remove_access',button.dataset.id,'Remove this user from the current business? The login account will not be permanently deleted.')));

    if(window.lucide)lucide.createIcons();
});
</script>
<?php include __DIR__ . '/includes/layout-end.php'; ?>
