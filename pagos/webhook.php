<?php
// Novis — webhook de Stripe: Stripe avisa aquí cuando un pago se completa.
// URL a configurar en Stripe: https://thenovis.com/pagos/webhook.php
// Eventos: checkout.session.completed, checkout.session.async_payment_succeeded,
//          customer.subscription.updated, customer.subscription.deleted, invoice.paid, invoice.payment_failed
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

/** Busca el pago de una suscripción por su id en Stripe. */
function pago_de_sub(?string $subId): ?array {
    if (!$subId) return null;
    $st = nv_db()->prepare('SELECT * FROM pagos WHERE stripe_sub = ?');
    $st->execute([$subId]);
    return $st->fetch() ?: null;
}

// Cambios en una suscripción: baja programada, reanudación, impago o fin
if (in_array($tipo, ['customer.subscription.updated', 'customer.subscription.deleted'], true) && ($p = pago_de_sub($s['id'] ?? null))) {
    $antes = nv_estado($p);
    nv_sub_guardar((int)$p['id'], $s);
    if ($tipo === 'customer.subscription.deleted' && $antes[0] !== 'cancelado') nv_aviso_sub($p, 'fin');
}

// Factura cobrada: si es de un ciclo nuevo (no la primera cuota), se registra la renovación
if ($tipo === 'invoice.paid') {
    $subId = is_string($s['subscription'] ?? null) ? $s['subscription'] : ($s['parent']['subscription_details']['subscription'] ?? null);
    $p = pago_de_sub($subId);
    if ($p && ($s['billing_reason'] ?? '') === 'subscription_cycle' && strtolower($s['currency'] ?? '') === $p['moneda']) nv_renovacion($p, $s);
}

// Cobro de renovación fallido
if ($tipo === 'invoice.payment_failed') {
    $subId = is_string($s['subscription'] ?? null) ? $s['subscription'] : ($s['parent']['subscription_details']['subscription'] ?? null);
    if ($p = pago_de_sub($subId)) {
        nv_db()->prepare("UPDATE pagos SET sub_estado = 'past_due' WHERE id = ?")->execute([$p['id']]);
        if (($s['billing_reason'] ?? '') === 'subscription_cycle' && (int)($s['attempt_count'] ?? 1) <= 1) nv_aviso_sub($p, 'fallo');
    }
}

salir(200, 'ok');
