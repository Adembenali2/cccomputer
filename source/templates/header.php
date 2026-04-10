<?php
if (!function_exists('csp_nonce')) {
    function csp_nonce(): string {
        $nonce = $GLOBALS['csp_nonce'] ?? '';
        return $nonce === '' ? '' : 'nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"';
    }
}
?>
<link rel="stylesheet" href="/assets/css/global.css">
<script src="/assets/js/darkmode.js" <?= csp_nonce() ?>></script>
<?php require_once __DIR__ . '/../../includes/header_nav.php'; ?>
