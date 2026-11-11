<?php
/**
 * Saved Delivery Addresses.
 *
 * One customer, many addresses, exactly one of them default. Every function
 * here takes a user id and scopes to it — an address belongs to whoever saved
 * it, and nothing below will read or write across that line.
 */
require_once __DIR__ . '/../config/db.php';

const ADDRESS_MAX_PER_USER = 10;

/** Every address this customer has saved, default first, then newest. */
function address_list(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, updated_at DESC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** One address, but only if it belongs to $userId. Null otherwise. */
function address_find(int $userId, int $addressId): ?array
{
    $stmt = db()->prepare('SELECT * FROM addresses WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$addressId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** The one checkout should start on: the default, or the most recent. */
function address_default(int $userId): ?array
{
    $all = address_list($userId);
    return $all[0] ?? null;
}

/**
 * Makes $addressId the only default for this customer.
 * Both statements run inside one transaction: a customer with two defaults,
 * or none, is worse than the state we started from.
 */
function address_set_default(int $userId, int $addressId): bool
{
    if (!address_find($userId, $addressId)) {
        return false;
    }
    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?')
           ->execute([$userId]);
        $db->prepare('UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?')
           ->execute([$addressId, $userId]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Creates or updates one address. Pass an id in $data to update; omit it to
 * create. Returns the address id, or null when the caller does not own the id
 * they passed.
 */
function address_save(int $userId, array $data): ?int
{
    $db = db();
    $id = isset($data['id']) ? (int) $data['id'] : 0;

    $label     = trim((string) ($data['label'] ?? 'Home')) ?: 'Home';
    $recipient = trim((string) ($data['recipient_name'] ?? ''));
    $phone     = trim((string) ($data['phone'] ?? ''));
    $address   = trim((string) ($data['address'] ?? ''));
    // A pin is optional and only stored when both halves are present — half a
    // coordinate points at the Gulf of Guinea.
    $lat = isset($data['latitude']) && $data['latitude'] !== '' ? (float) $data['latitude'] : null;
    $lng = isset($data['longitude']) && $data['longitude'] !== '' ? (float) $data['longitude'] : null;
    if ($lat === null || $lng === null) {
        $lat = null;
        $lng = null;
    }

    if ($address === '') {
        return null;
    }

    if ($id > 0) {
        if (!address_find($userId, $id)) {
            return null; // not theirs
        }
        $db->prepare(
            'UPDATE addresses
                SET label = ?, recipient_name = ?, phone = ?, address = ?,
                    latitude = ?, longitude = ?
              WHERE id = ? AND user_id = ?'
        )->execute([$label, $recipient, $phone, $address, $lat, $lng, $id, $userId]);
        return $id;
    }

    $countStmt = $db->prepare('SELECT COUNT(*) FROM addresses WHERE user_id = ?');
    $countStmt->execute([$userId]);
    $existing = (int) $countStmt->fetchColumn();
    if ($existing >= ADDRESS_MAX_PER_USER) {
        return null;
    }

    $db->prepare(
        'INSERT INTO addresses (user_id, label, recipient_name, phone, address, latitude, longitude, is_default)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$userId, $label, $recipient, $phone, $address, $lat, $lng, $existing === 0 ? 1 : 0]);

    return (int) $db->lastInsertId();
}

/**
 * Deletes one address. If it was the default and others remain, the newest of
 * those is promoted — leaving a customer with addresses but no default makes
 * checkout start on nothing.
 */
function address_delete(int $userId, int $addressId): bool
{
    $existing = address_find($userId, $addressId);
    if (!$existing) {
        return false;
    }

    $db = db();
    $db->prepare('DELETE FROM addresses WHERE id = ? AND user_id = ?')
       ->execute([$addressId, $userId]);

    if ((int) $existing['is_default'] === 1) {
        $next = address_list($userId);
        if ($next) {
            address_set_default($userId, (int) $next[0]['id']);
        }
    }
    return true;
}

/** The public shape of an address, as the API returns it. */
function address_public(array $row): array
{
    return [
        'id'             => (int) $row['id'],
        'label'          => (string) $row['label'],
        'recipient_name' => (string) $row['recipient_name'],
        'phone'          => (string) $row['phone'],
        'address'        => (string) $row['address'],
        // Strings, not floats: the app binds them straight into text, and a
        // JSON float would render as 14.599512000000001 on some devices.
        'latitude'       => $row['latitude'] === null ? '' : (string) $row['latitude'],
        'longitude'      => $row['longitude'] === null ? '' : (string) $row['longitude'],
        'is_default'     => ((int) $row['is_default']) === 1,
        // Ready-made for the admin order view and for a rider's phone.
        'map_url'        => $row['latitude'] === null ? '' :
            'https://www.google.com/maps/search/?api=1&query=' . $row['latitude'] . ',' . $row['longitude'],
    ];
}
