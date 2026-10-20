<?php
// Novis — webhook de Stripe: Stripe avisa aquí cuando un pago se completa.
// URL a configurar en Stripe: https://thenovis.com/pagos/webhook.php
// Evento: checkout.session.completed (y checkout.session.async_payment_succeeded)
require_once __DIR__ . '/../lib/cuentas.php';

header('Content-Type: application/json');
$secreto = (string)(novis_config()['stripe_webhook_secret'] ?? '');
$cuerpo = file_get_contents('php://input');
$firma = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

function salir(int $code, string $msg): void { http_response_code($code); echo json_encode(['msg' => $msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') salir(405, 'Método no permitido');
if ($secreto === '') salir(503, 'Webhook sin configurar');

// Verificar la firma de Stripe (cabecera "t=...,v1=...")
$t = null; $v1 = [];
foreach (explode(',', $firma) as $par) {
    [$k, $val] = array_pad(explode('=', trim($par), 2), 2, '');
    if ($k === 't') $t = (int)$val;
    if ($k === 'v1') $v1[] = $val;
}
if (!$t || !$v1 || abs(time() - $t) > 300) salir(400, 'Firma no válida');
$esperada = hash_hmac('sha256', $t . '.' . $cuerpo, $secreto);
$ok = false;
foreach ($v1 as $f) if (hash_equals($esperada, $f)) $ok = true;
if (!$ok) salir(400, 'Firma no válida');

$evento = json_decode($cuerpo, true);
$tipo = $evento['type'] ?? '';
$s = $evento['data']['object'] ?? [];

if (in_array($tipo, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)
    && ($s['payment_status'] ?? '') === 'paid') {
    $pagoId = (int)($s['metadata']['pago_id'] ?? 0);
    $st = nv_db()->prepare('SELECT * FROM pagos WHERE id = ?');
    $st->execute([$pagoId]);
    $p = $st->fetch();
    // Comprobación extra: el importe cobrado debe coincidir con el del código
    if ($p && (int)($s['amount_total'] ?? -1) === (int)$p['importe'] && strtolower($s['currency'] ?? '') === $p['moneda']) {
        nv_marcar_pagado($pagoId, $s);
    } else {
        error_log('Novis webhook: pago ' . $pagoId . ' no encontrado o importe distinto');
    }
}

salir(200, 'ok');
