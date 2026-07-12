<footer class="app-footer">RAD LINK HEALTH © <?= date('Y') ?></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>window.APP_BASE_URL=<?= json_encode(app_base_url()) ?>;window.APP_CSRF=<?= json_encode(csrf_token()) ?>;</script>
<script src="<?= e(app_url('assets/js/app.js')) ?>"></script>
