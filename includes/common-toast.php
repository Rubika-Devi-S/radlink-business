<div id="appToastStack" class="app-toast-stack" aria-live="polite" aria-atomic="true"></div>
<?php if (!empty($flashMessage)): ?>
<script>
window.__APP_FLASH__ = <?= json_encode($flashMessage, JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php endif; ?>
