<?php
declare(strict_types=1);
/* Registers the person or company behind a website request as a customer, so invoices, quotations and the
   other business documents can pull their details instead of asking for them again.
   Never throws: a problem here must not affect the client's submission. */

/**
 * @param array{name:string,contact_person?:string,tin?:string,address?:string,phone?:string,email?:string,note?:string} $c
 * @return int|null customer id (existing or new), or null when nothing could be saved
 */
function capture_customer(PDO $pdo, array $c): ?int {
    $GLOBALS['capture_customer_error'] = '';
    try {
        $name = mb_substr(trim((string) ($c['name'] ?? '')), 0, 190);
        if (mb_strlen($name) < 2) { $GLOBALS['capture_customer_error'] = 'the client name is missing'; return null; }
        $contact = mb_substr(trim((string) ($c['contact_person'] ?? '')), 0, 190);
        if (strcasecmp($contact, $name) === 0) $contact = '';
        $tin = preg_replace('/\s+/', '', (string) ($c['tin'] ?? ''));
        if (!preg_match('/^\d{10}$/', (string) $tin)) $tin = '';
        $address = mb_substr(trim((string) ($c['address'] ?? '')), 0, 500);
        $phone = mb_substr(trim((string) ($c['phone'] ?? '')), 0, 60);
        $email = strtolower(mb_substr(trim((string) ($c['email'] ?? '')), 0, 190));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';

        $id = 0; $row = null;
        $find = function (string $sql, array $args) use ($pdo): ?array { $s = $pdo->prepare($sql); $s->execute($args); return $s->fetch() ?: null; };
        if ($tin !== '') $row = $find('SELECT * FROM customers WHERE tin = ? ORDER BY id LIMIT 1', [$tin]);
        if (!$row) $row = $find('SELECT * FROM customers WHERE LOWER(name) = ? ORDER BY id LIMIT 1', [mb_strtolower($name)]);

        if ($row) {                                   // already registered: only fill in what is still empty, never overwrite
            $id = (int) $row['id']; $fill = [];
            foreach (['contact_person' => $contact, 'tin' => $tin, 'address' => $address, 'phone' => $phone, 'email' => $email] as $col => $val) {
                if ($val !== '' && trim((string) $row[$col]) === '') $fill[$col] = $val;
            }
            if (isset($fill['tin']) && $find('SELECT id FROM customers WHERE tin = ? AND id <> ?', [$tin, $id])) unset($fill['tin']);
            if ($fill) {
                $pdo->prepare('UPDATE customers SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($fill))) . ', updated_at = ? WHERE id = ?')
                    ->execute([...array_values($fill), gmdate('Y-m-d H:i:s'), $id]);
            }
            return $id;
        }
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO customers (name, contact_person, tin, address, phone, email, logo_file, notes, is_active, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,NULL,?,1,NULL,?,?)')
            ->execute([$name, $contact ?: null, $tin ?: null, $address ?: null, $phone ?: null, $email ?: null, mb_substr((string) ($c['note'] ?? 'Registered automatically from a website request.'), 0, 1000), $now, $now]);
        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) { error_log('capture_customer: ' . $e->getMessage()); $GLOBALS['capture_customer_error'] = $e->getMessage(); return null; }
}
