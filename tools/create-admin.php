<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from the command line.\n");
    exit(1);
}

$name = trim((string) getenv('FUNDILINK_ADMIN_NAME'));
$email = mb_strtolower(trim((string) getenv('FUNDILINK_ADMIN_EMAIL')));
$rawPhone = trim((string) getenv('FUNDILINK_ADMIN_PHONE'));
$phone = $rawPhone === '' ? null : preg_replace('/\D+/', '', $rawPhone);
$password = (string) getenv('FUNDILINK_ADMIN_PASSWORD');

if ($phone !== null && strlen($phone) === 10 && $phone[0] === '0') {
    $phone = '254' . substr($phone, 1);
} elseif ($phone !== null && strlen($phone) === 9) {
    $phone = '254' . $phone;
}

if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Enter a name and a valid email address.\n");
    exit(1);
}
if ($phone !== null && !preg_match('/^254[17]\d{8}$/', $phone)) {
    fwrite(STDERR, "Enter a valid Kenyan phone number or leave it blank.\n");
    exit(1);
}
if (strlen($password) < 12) {
    fwrite(STDERR, "The administrator password must contain at least 12 characters.\n");
    exit(1);
}

$host = getenv('FUNDILINK_DB_HOST') ?: '127.0.0.1';
$db = getenv('FUNDILINK_DB_NAME') ?: 'fundilink_marketplace';
$user = getenv('FUNDILINK_DB_USER') ?: 'root';
$dbPassword = getenv('FUNDILINK_DB_PASSWORD') ?: '';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset=utf8mb4",
        $user,
        $dbPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $activeAdminCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1"
    )->fetchColumn();
    if ($activeAdminCount > 0) {
        fwrite(STDERR, "An active administrator already exists. Sign in to manage accounts.\n");
        exit(1);
    }

    $statement = $pdo->prepare(
        'INSERT INTO users (name, phone, email, password_hash, role, is_active)
         VALUES (?, ?, ?, ?, \'admin\', 1)'
    );
    $statement->execute([$name, $phone, $email, password_hash($password, PASSWORD_DEFAULT)]);
    fwrite(STDOUT, "Administrator account created for {$email}.\n");
} catch (PDOException $exception) {
    error_log('FundiLink admin setup failed: ' . $exception->getMessage());
    fwrite(STDERR, "Could not create the administrator account. Check the database settings and PHP error log.\n");
    exit(1);
} finally {
    $password = '';
    putenv('FUNDILINK_ADMIN_PASSWORD');
}
