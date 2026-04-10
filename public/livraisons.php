<?php
/**
 * Alias canonique (spec notifications / liens) → livraison.php
 */
declare(strict_types=1);

$q = $_SERVER['QUERY_STRING'] ?? '';
$target = '/public/livraison.php' . ($q !== '' ? '?' . $q : '');
header('Location: ' . $target, true, 302);
exit;
