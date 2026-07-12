<?php
if (!isset($pageTitle)) $pageTitle = 'RAD LINK HEALTH';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#7541d8">
<title><?= e($pageTitle) ?> | RAD LINK HEALTH</title>
<?php include __DIR__ . '/links.php'; ?>
</head>
<body>
<?php include __DIR__ . '/common-toast.php'; ?>
<?php include __DIR__ . '/sidebar.php'; ?>
<div id="appMain">
<?php include __DIR__ . '/topbar.php'; ?>
<main class="page-content">
