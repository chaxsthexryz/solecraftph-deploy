<?php
/**
 * Site Settings Service (Settings & Configuration module).
 * Simple key/value store backing currency, shipping, payment
 * toggles, and store contact info — editable from admin/settings.php.
 */
require_once __DIR__ . '/../config/db.php';

function settings_all(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache;
}

function setting(string $key, $default = null)
{
    $all = settings_all();
    return $all[$key] ?? $default;
}

function settings_update(array $values): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($values as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

function settings_currency(): string
{
    return setting('currency_symbol', '₱');
}

function settings_shipping_fee(): float
{
    return (float) setting('shipping_fee', 150);
}

function settings_free_shipping_threshold(): float
{
    return (float) setting('free_shipping_threshold', 2000);
}

function settings_shipping_for(float $subtotal): float
{
    // Nothing in the cart, nothing to ship. Without this an empty cart quotes
    // the flat fee, which the cart API returned as a ₱150 total on zero items.
    if ($subtotal <= 0) {
        return 0.0;
    }
    $threshold = settings_free_shipping_threshold();
    return $subtotal >= $threshold ? 0.0 : settings_shipping_fee();
}

/** Which payment methods are currently enabled, keyed by code -> label */
function settings_enabled_payment_methods(): array
{
    $methods = [];
    if (setting('payment_cod_enabled', '1') === '1') $methods['COD'] = 'Cash on Delivery';
    if (setting('payment_gcash_enabled', '1') === '1') $methods['GCASH'] = 'GCash';
    if (setting('payment_card_enabled', '1') === '1') $methods['CARD'] = 'Credit / Debit Card';
    return $methods ?: ['COD' => 'Cash on Delivery'];
}
