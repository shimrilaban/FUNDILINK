
<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FUNDILINK API CONFIGURATION
|--------------------------------------------------------------------------
| Supports InfinityFree hosting and local XAMPP development.
| Never commit database passwords to GitHub.
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ini_set('display_errors', '0');

/*
|--------------------------------------------------------------------------
| JSON RESPONSE
|--------------------------------------------------------------------------
*/

function respond(int $status, array $data): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| ERROR HANDLING
|--------------------------------------------------------------------------
*/

set_error_handler(
    static function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException(
            $message,
            0,
            $severity,
            $file,
            $line
        );
    }
);

set_exception_handler(
    static function (Throwable $exception): void {
        error_log(
            'FundiLink unhandled API error: ' .
            $exception->getMessage()
        );

        respond(500, [
            'error' => 'An unexpected server error occurred. Check the PHP error log.'
        ]);
    }
);

/*
|--------------------------------------------------------------------------
| DATABASE CONFIGURATION
|--------------------------------------------------------------------------
| Priority:
| 1. Environment variables
| 2. Private config.local.php
| 3. Local development defaults
|--------------------------------------------------------------------------
*/

$localConfigPath = __DIR__ . '/config.local.php';

$localDbConfig = [];

if (is_file($localConfigPath)) {
    $loadedConfig = require $localConfigPath;

    if (is_array($loadedConfig)) {
        $localDbConfig = $loadedConfig;
    }
}

$host = getenv('FUNDILINK_DB_HOST')
    ?: ($localDbConfig['host'] ?? '127.0.0.1');

$db = getenv('FUNDILINK_DB_NAME')
    ?: ($localDbConfig['database'] ?? 'fundilink_marketplace');

$user = getenv('FUNDILINK_DB_USER')
    ?: ($localDbConfig['username'] ?? 'root');

$password = getenv('FUNDILINK_DB_PASSWORD');

if ($password === false) {
    $password = $localDbConfig['password'] ?? '';
}

/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

} catch (PDOException $exception) {

    error_log(
        'FundiLink database connection failed: ' .
        $exception->getMessage()
    );

    respond(500, [
        'error' => 'Could not connect to the hosting database. Check your database configuration.'
    ]);
}

/*
|--------------------------------------------------------------------------
| SECURE SESSION CONFIGURATION
|--------------------------------------------------------------------------
*/

$secureCookie = (
    isset($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] !== '' &&
    $_SERVER['HTTPS'] !== 'off'
);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => $secureCookie,
]);

if (!session_start()) {
    respond(500, [
        'error' => 'Could not start a secure session.'
    ]);
}

/*
|--------------------------------------------------------------------------
| JSON INPUT
|--------------------------------------------------------------------------
*/

function read_json(): array
{
    $data = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($data)) {
        respond(400, [
            'error' => 'Request body must be valid JSON.'
        ]);
    }

    return $data;
}

/*
|--------------------------------------------------------------------------
| CURRENT USER
|--------------------------------------------------------------------------
*/

function current_user(PDO $pdo): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT id, name, phone, email, role, is_active
         FROM users
         WHERE id = ?'
    );

    $statement->execute([
        (int) $_SESSION['uid']
    ]);

    $user = $statement->fetch();

    if (!$user || !(bool) $user['is_active']) {
        unset($_SESSION['uid']);
        return null;
    }

    $user['id'] = (int) $user['id'];
    $user['is_active'] = (bool) $user['is_active'];

    return $user;
}

/*
|--------------------------------------------------------------------------
| AUTHENTICATION AND ROLE PERMISSIONS
|--------------------------------------------------------------------------
*/

function require_user(PDO $pdo, array $roles = []): array
{
    $user = current_user($pdo);

    if (!$user) {
        respond(401, [
            'error' => 'Sign in to continue.'
        ]);
    }

    if (
        $roles &&
        !in_array($user['role'], $roles, true)
    ) {
        respond(403, [
            'error' => 'Your account does not have permission for this action.'
        ]);
    }

    return $user;
}

/*
|--------------------------------------------------------------------------
| CSRF PROTECTION
|--------------------------------------------------------------------------
*/

function require_csrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        !is_string($provided) ||
        empty($_SESSION['csrf']) ||
        !hash_equals($_SESSION['csrf'], $provided)
    ) {
        respond(419, [
            'error' => 'Your session expired. Refresh the page and try again.'
        ]);
    }
}

function create_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(
            random_bytes(32)
        );
    }

    return $_SESSION['csrf'];
}

/*
|--------------------------------------------------------------------------
| KENYAN PHONE NUMBER VALIDATION
|--------------------------------------------------------------------------
*/

function normalize_phone(array $data): string
{
    $phone = preg_replace(
        '/\D+/',
        '',
        (string) ($data['phone'] ?? '')
    );

    if (
        strlen($phone) === 10 &&
        $phone[0] === '0'
    ) {
        $phone = '254' . substr($phone, 1);

    } elseif (strlen($phone) === 9) {

        $phone = '254' . $phone;
    }

    if (!preg_match('/^254[17]\d{8}$/', $phone)) {
        respond(422, [
            'error' => 'Enter a valid Kenyan phone number.'
        ]);
    }

    return $phone;
}

function canonical_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);

    if (
        strlen($digits) === 10 &&
        $digits[0] === '0'
    ) {
        return '254' . substr($digits, 1);
    }

    if (strlen($digits) === 9) {
        return '254' . $digits;
    }

    return $digits;
}

/*
|--------------------------------------------------------------------------
| REQUIRED STRING VALIDATION
|--------------------------------------------------------------------------
*/

function required_string(
    array $data,
    string $key,
    int $maxLength = 5000
): string {

    $value = $data[$key] ?? null;

    if (
        !is_string($value) ||
        trim($value) === '' ||
        mb_strlen(trim($value)) > $maxLength
    ) {
        respond(422, [
            'error' => "A valid {$key} is required."
        ]);
    }

    return trim($value);
}

/*
|--------------------------------------------------------------------------
| NUMBER VALIDATION
|--------------------------------------------------------------------------
*/

function required_number(
    array $data,
    string $key,
    float $min = 0,
    float $max = 100000000
): float {

    $value = $data[$key] ?? null;

    if (
        !is_numeric($value) ||
        (float) $value < $min ||
        (float) $value > $max
    ) {
        respond(422, [
            'error' => "A valid {$key} is required."
        ]);
    }

    return (float) $value;
}

/*
|--------------------------------------------------------------------------
| DATE VALIDATION
|--------------------------------------------------------------------------
*/

function valid_date(array $data, string $key): string
{
    $value = required_string($data, $key, 10);

    $date = DateTime::createFromFormat(
        '!Y-m-d',
        $value
    );

    if (
        !$date ||
        $date->format('Y-m-d') !== $value
    ) {
        respond(422, [
            'error' => "A valid {$key} date is required."
        ]);
    }

    return $value;
}

/*
|--------------------------------------------------------------------------
| SUPPORTED SKILLED TRADES
|--------------------------------------------------------------------------
*/

function allowed_trade(array $data): string
{
    $trade = required_string(
        $data,
        'trade',
        40
    );

    $trades = [
        'Plumber',
        'Electrician',
        'Mason',
        'Carpenter',
        'Mechanic',
        'Painter',
        'Welder',
        'Tiler'
    ];

    if (!in_array($trade, $trades, true)) {
        respond(422, [
            'error' => 'Select a supported trade.'
        ]);
    }

    return $trade;
}
