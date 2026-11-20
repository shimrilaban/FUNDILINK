<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$resource = $_GET['resource'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function session_user(PDO $pdo, array $user): array
{
    $user['id'] = (int) $user['id'];
    $user['email'] = $user['email'] ?? null;
    $user['workerId'] = null;
    if ($user['role'] === 'worker') {
        $statement = $pdo->prepare('SELECT id FROM workers WHERE user_id = ?');
        $statement->execute([$user['id']]);
        $workerId = $statement->fetchColumn();
        $user['workerId'] = $workerId === false ? null : (int) $workerId;
    }
    return $user;
}

function worker_profile_values(array $data, string $name, string $phone): array
{
    $trade = allowed_trade($data);
    $experience = (int) required_number($data, 'exp', 0, 60);
    $area = required_string($data, 'area', 180);
    $rate = required_number($data, 'rate');
    $skills = required_string($data, 'skills', 1500);
    $skills = array_values(array_filter(array_map('trim', explode(',', $skills))));
    if (!$skills) {
        respond(422, ['error' => 'Add at least one skill.']);
    }
    $bio = isset($data['bio']) && is_string($data['bio']) ? trim($data['bio']) : '';
    return [$name, $trade, $area, $rate, $experience, $phone, json_encode($skills), $bio];
}

try {
    if ($resource === 'bootstrap' && $method === 'GET') {
        $user = current_user($pdo);
        $workerFields = 'id, name, trade, area, rate, experience AS exp, verified';
        if ($user) {
            $workerFields .= ', phone';
        }
        $workerFields .= ', skills, bio';
        $workers = $pdo->query(
            "SELECT {$workerFields}
             FROM workers
             WHERE user_id IS NULL
                OR EXISTS (SELECT 1 FROM users u WHERE u.id = workers.user_id AND u.is_active = 1)
             ORDER BY id DESC"
        )->fetchAll();
        $reviews = $pdo->query(
            'SELECT worker_id, stars AS s, comment AS t FROM reviews ORDER BY id'
        )->fetchAll();
        foreach ($workers as &$worker) {
            $worker['id'] = (int) $worker['id'];
            $worker['rate'] = (float) $worker['rate'];
            $worker['exp'] = (int) $worker['exp'];
            $worker['verified'] = (bool) $worker['verified'];
            $worker['skills'] = json_decode($worker['skills'] ?? '[]', true) ?: [];
            $worker['reviews'] = [];
        }
        unset($worker);
        $workerIndexes = [];
        foreach ($workers as $index => $worker) {
            $workerIndexes[$worker['id']] = $index;
        }
        foreach ($reviews as $review) {
            $workerId = (int) $review['worker_id'];
            if (isset($workerIndexes[$workerId])) {
                $workers[$workerIndexes[$workerId]]['reviews'][] = [
                    's' => (int) $review['s'],
                    't' => $review['t'],
                ];
            }
        }

        $jobFields = 'id, name';
        if ($user) {
            $jobFields .= ', phone';
        }
        $jobFields .= ', trade, location AS loc, job_date AS `when`, budget, description AS `desc`';
        $jobs = $pdo->query(
            "SELECT {$jobFields}
             FROM jobs
             WHERE status = 'open'
               AND (client_id IS NULL OR EXISTS (
                   SELECT 1 FROM users u WHERE u.id = jobs.client_id AND u.is_active = 1
               ))
             ORDER BY id DESC"
        )->fetchAll();
        foreach ($jobs as &$job) {
            $job['id'] = (int) $job['id'];
            $job['budget'] = (float) $job['budget'];
        }
        unset($job);

        respond(200, [
            'workers' => $workers,
            'jobs' => $jobs,
            'user' => $user ? session_user($pdo, $user) : null,
            'csrf' => create_csrf_token(),
        ]);
    }

    if ($resource === 'auth-register' && $method === 'POST') {
        require_csrf();
        $data = read_json();
        $name = required_string($data, 'name', 120);
        $phone = normalize_phone($data);
        $password = $data['password'] ?? '';
        $role = $data['role'] ?? '';
        if (!is_string($password) || strlen($password) < 8) {
            respond(422, ['error' => 'Password must be at least 8 characters.']);
        }
        if (!in_array($role, ['client', 'worker'], true)) {
            respond(422, ['error' => 'Choose a valid account type.']);
        }
        $email = isset($data['email']) && trim((string) $data['email']) !== ''
            ? mb_strtolower(trim((string) $data['email']))
            : null;
        if ($email !== null && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120)) {
            respond(422, ['error' => 'Enter a valid email address.']);
        }
        $workerValues = $role === 'worker' ? worker_profile_values($data, $name, $phone) : null;

        $duplicate = $pdo->prepare('SELECT id FROM users WHERE phone = ? OR (email IS NOT NULL AND email = ?)');
        $duplicate->execute([$phone, $email]);
        if ($duplicate->fetch()) {
            respond(409, ['error' => 'An account already exists for that phone number or email. Sign in instead.']);
        }

        $pdo->beginTransaction();
        $insertUser = $pdo->prepare('INSERT INTO users (name, phone, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
        $insertUser->execute([$name, $phone, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        $userId = (int) $pdo->lastInsertId();

        if ($role === 'worker') {
            $localPhone = '0' . substr($phone, 3);
            $existingWorker = $pdo->prepare(
                'SELECT id, user_id FROM workers WHERE phone IN (?, ?) ORDER BY id LIMIT 1 FOR UPDATE'
            );
            $existingWorker->execute([$phone, $localPhone]);
            $existing = $existingWorker->fetch();
            if ($existing && $existing['user_id'] !== null) {
                $pdo->rollBack();
                respond(409, ['error' => 'A fundi profile already uses that phone number. Contact support to link it.']);
            }
            if ($existing) {
                $update = $pdo->prepare(
                    'UPDATE workers SET user_id = ?, name = ?, trade = ?, area = ?, rate = ?, experience = ?, phone = ?, skills = ?, bio = ? WHERE id = ?'
                );
                $update->execute(array_merge([$userId], $workerValues, [(int) $existing['id']]));
            } else {
                $insertWorker = $pdo->prepare(
                    'INSERT INTO workers (user_id, name, trade, area, rate, experience, phone, skills, bio)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insertWorker->execute(array_merge([$userId], $workerValues));
            }
        }

        if ($role === 'client') {
            $localPhone = '0' . substr($phone, 3);
            $linkBookings = $pdo->prepare(
                'UPDATE bookings SET client_id = ? WHERE client_id IS NULL AND phone IN (?, ?)'
            );
            $linkBookings->execute([$userId, $phone, $localPhone]);
            $linkJobs = $pdo->prepare(
                'UPDATE jobs SET client_id = ? WHERE client_id IS NULL AND phone IN (?, ?)'
            );
            $linkJobs->execute([$userId, $phone, $localPhone]);
        }

        $pdo->commit();
        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $user = current_user($pdo);
        respond(201, ['user' => session_user($pdo, $user), 'csrf' => $_SESSION['csrf']]);
    }

    if ($resource === 'auth-login' && $method === 'POST') {
        require_csrf();
        $data = read_json();
        $identifier = trim((string) ($data['identifier'] ?? $data['phone'] ?? ''));
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $statement = $pdo->prepare('SELECT id, name, phone, email, role, is_active, password_hash FROM users WHERE email = ?');
            $statement->execute([mb_strtolower($identifier)]);
        } else {
            $phone = normalize_phone(['phone' => $identifier]);
            $statement = $pdo->prepare('SELECT id, name, phone, email, role, is_active, password_hash FROM users WHERE phone = ?');
            $statement->execute([$phone]);
        }
        $password = $data['password'] ?? '';
        if (!is_string($password)) {
            respond(422, ['error' => 'Enter your password.']);
        }
        $user = $statement->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            respond(401, ['error' => 'Phone number or password is incorrect.']);
        }
        if (!(bool) $user['is_active']) {
            respond(403, ['error' => 'This account has been deactivated. Contact FundiLink support.']);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        unset($user['password_hash']);
        respond(200, ['user' => session_user($pdo, $user), 'csrf' => $_SESSION['csrf']]);
    }

    if ($resource === 'admin-dashboard' && $method === 'GET') {
        require_user($pdo, ['admin']);
        $stats = $pdo->query(
            "SELECT
                COUNT(*) AS total_users,
                COALESCE(SUM(is_active = 1), 0) AS active_users,
                COALESCE(SUM(is_active = 0), 0) AS deactivated_users,
                (SELECT COUNT(*) FROM workers w
                 WHERE w.user_id IS NULL OR EXISTS (
                     SELECT 1 FROM users u
                     WHERE u.id = w.user_id AND u.role = 'worker' AND u.is_active = 1
                 )) AS active_fundis,
                (SELECT COUNT(*) FROM jobs) AS total_jobs,
                (SELECT COUNT(*) FROM jobs j
                 WHERE j.status = 'open'
                   AND (j.client_id IS NULL OR EXISTS (
                       SELECT 1 FROM users u WHERE u.id = j.client_id AND u.is_active = 1
                   ))) AS open_jobs,
                (SELECT COUNT(*) FROM bookings) AS total_bookings,
                (SELECT COUNT(*) FROM bookings WHERE status = 'pending') AS pending_bookings,
                (SELECT COUNT(*) FROM bookings WHERE status = 'completed') AS completed_bookings,
                (SELECT COUNT(*) FROM reviews) AS total_reviews
             FROM users"
        )->fetch();
        foreach ($stats as &$value) {
            $value = (int) $value;
        }
        unset($value);

        $recentUsers = $pdo->query(
            'SELECT id, name, phone, email, role, is_active AS active, created_at
             FROM users ORDER BY created_at DESC, id DESC LIMIT 6'
        )->fetchAll();
        $recentJobs = $pdo->query(
            'SELECT j.id, j.trade, j.location, j.job_date, j.budget, j.description, j.status,
                    j.created_at, COALESCE(u.name, j.name) AS client_name
             FROM jobs j LEFT JOIN users u ON u.id = j.client_id
             ORDER BY j.created_at DESC, j.id DESC LIMIT 6'
        )->fetchAll();
        $recentBookings = $pdo->query(
            'SELECT b.id, b.name AS client_name, b.phone AS client_phone,
                    w.name AS worker_name, w.trade, b.booking_date, b.status, b.created_at
             FROM bookings b INNER JOIN workers w ON w.id = b.worker_id
             ORDER BY b.created_at DESC, b.id DESC LIMIT 8'
        )->fetchAll();
        $recentReviews = $pdo->query(
            'SELECT r.stars, r.comment, r.created_at, w.name AS worker_name
             FROM reviews r INNER JOIN workers w ON w.id = r.worker_id
             ORDER BY r.created_at DESC, r.id DESC LIMIT 5'
        )->fetchAll();
        $statusCounts = $pdo->query(
            "SELECT status, COUNT(*) AS count FROM bookings GROUP BY status"
        )->fetchAll();
        foreach ($statusCounts as &$statusCount) {
            $statusCount['count'] = (int) $statusCount['count'];
        }
        unset($statusCount);
        respond(200, [
            'stats' => $stats,
            'recentUsers' => $recentUsers,
            'recentJobs' => $recentJobs,
            'recentBookings' => $recentBookings,
            'recentReviews' => $recentReviews,
            'bookingStatuses' => $statusCounts,
        ]);
    }

    if ($resource === 'admin-users' && $method === 'GET') {
        require_user($pdo, ['admin']);
        $users = $pdo->query(
            'SELECT u.id, u.name, u.phone, u.email, u.role, u.is_active AS active, u.created_at,
                    w.trade, w.area
             FROM users u
             LEFT JOIN workers w ON w.user_id = u.id
             ORDER BY u.created_at DESC, u.id DESC'
        )->fetchAll();
        foreach ($users as &$listedUser) {
            $listedUser['id'] = (int) $listedUser['id'];
            $listedUser['active'] = (bool) $listedUser['active'];
        }
        unset($listedUser);
        respond(200, ['users' => $users]);
    }

    if ($resource === 'admin-users' && $method === 'PATCH') {
        require_csrf();
        $admin = require_user($pdo, ['admin']);
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            respond(400, ['error' => 'A valid user id is required.']);
        }
        $data = read_json();
        if (!isset($data['active']) || !is_bool($data['active'])) {
            respond(422, ['error' => 'Choose whether the account should be active.']);
        }
        if ((int) $admin['id'] === $id && !$data['active']) {
            respond(409, ['error' => 'You cannot deactivate your own administrator account.']);
        }

        $pdo->beginTransaction();
        $target = $pdo->prepare('SELECT role, is_active FROM users WHERE id = ? FOR UPDATE');
        $target->execute([$id]);
        $targetUser = $target->fetch();
        if (!$targetUser) {
            $pdo->rollBack();
            respond(404, ['error' => 'User not found.']);
        }
        if ($targetUser['role'] === 'admin' && !(bool) $data['active']) {
            $activeAdmins = $pdo->query(
                "SELECT id FROM users WHERE role = 'admin' AND is_active = 1 FOR UPDATE"
            )->fetchAll();
            if (count($activeAdmins) <= 1) {
                $pdo->rollBack();
                respond(409, ['error' => 'The last active administrator cannot be deactivated.']);
            }
        }

        $update = $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?');
        $update->execute([$data['active'] ? 1 : 0, $id]);
        $pdo->commit();
        respond(200, ['ok' => true, 'active' => $data['active']]);
    }

    if ($resource === 'admin-jobs' && $method === 'PATCH') {
        require_csrf();
        require_user($pdo, ['admin']);
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            respond(400, ['error' => 'A valid job id is required.']);
        }
        $data = read_json();
        $status = $data['status'] ?? '';
        if (!in_array($status, ['open', 'closed'], true)) {
            respond(422, ['error' => 'Choose whether the job should be open or closed.']);
        }
        $statement = $pdo->prepare('UPDATE jobs SET status = ? WHERE id = ?');
        $statement->execute([$status, $id]);
        if ($statement->rowCount() === 0) {
            $check = $pdo->prepare('SELECT id FROM jobs WHERE id = ?');
            $check->execute([$id]);
            if (!$check->fetch()) {
                respond(404, ['error' => 'Job not found.']);
            }
        }
        respond(200, ['ok' => true, 'status' => $status]);
    }

    if ($resource === 'auth-logout' && $method === 'POST') {
        require_csrf();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        respond(200, ['ok' => true]);
    }

    if ($resource === 'bookings' && $method === 'GET') {
        $user = require_user($pdo);
        if ($user['role'] === 'client') {
            $statement = $pdo->prepare(
                'SELECT b.id, b.worker_id AS wid, b.name, b.phone, b.booking_date AS `when`,
                        b.description AS `desc`, b.status, b.rated
                 FROM bookings b
                 WHERE b.client_id = ?
                 ORDER BY b.id DESC'
            );
            $statement->execute([$user['id']]);
        } else {
            $statement = $pdo->prepare(
                'SELECT b.id, b.worker_id AS wid, b.name, b.phone, b.booking_date AS `when`,
                        b.description AS `desc`, b.status, b.rated
                 FROM bookings b
                 INNER JOIN workers w ON w.id = b.worker_id
                 WHERE w.user_id = ?
                 ORDER BY b.id DESC'
            );
            $statement->execute([$user['id']]);
        }
        $bookings = $statement->fetchAll();
        foreach ($bookings as &$booking) {
            $booking['id'] = (int) $booking['id'];
            $booking['wid'] = (int) $booking['wid'];
            $booking['rated'] = (bool) $booking['rated'];
        }
        unset($booking);
        respond(200, ['bookings' => $bookings]);
    }

    if ($resource === 'workers' && $method === 'POST') {
        require_csrf();
        $user = require_user($pdo, ['worker']);
        $values = worker_profile_values(read_json(), $user['name'], $user['phone']);
        $check = $pdo->prepare('SELECT id FROM workers WHERE user_id = ?');
        $check->execute([$user['id']]);
        if ($check->fetch()) {
            respond(409, ['error' => 'Your account already has a fundi profile.']);
        }
        $statement = $pdo->prepare(
            'INSERT INTO workers (user_id, name, trade, area, rate, experience, phone, skills, bio)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute(array_merge([$user['id']], $values));
        respond(201, ['id' => (int) $pdo->lastInsertId()]);
    }

    if ($resource === 'jobs' && $method === 'POST') {
        require_csrf();
        $user = require_user($pdo, ['client']);
        $data = read_json();
        $trade = allowed_trade($data);
        $location = required_string($data, 'loc', 180);
        $date = valid_date($data, 'when');
        $budget = isset($data['budget']) && $data['budget'] !== '' ? required_number($data, 'budget') : 0;
        $description = required_string($data, 'desc');
        $statement = $pdo->prepare(
            'INSERT INTO jobs (client_id, name, phone, trade, location, job_date, budget, description)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$user['id'], $user['name'], $user['phone'], $trade, $location, $date, $budget, $description]);
        respond(201, ['id' => (int) $pdo->lastInsertId()]);
    }

    if ($resource === 'bookings' && $method === 'POST') {
        require_csrf();
        $user = require_user($pdo, ['client']);
        $data = read_json();
        $workerId = (int) required_number($data, 'wid', 1, PHP_INT_MAX);
        $workerCheck = $pdo->prepare('SELECT id FROM workers WHERE id = ?');
        $workerCheck->execute([$workerId]);
        if (!$workerCheck->fetch()) {
            respond(404, ['error' => 'The selected fundi was not found.']);
        }
        $date = valid_date($data, 'when');
        $description = required_string($data, 'desc');
        $statement = $pdo->prepare(
            'INSERT INTO bookings (client_id, worker_id, name, phone, booking_date, description)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$user['id'], $workerId, $user['name'], $user['phone'], $date, $description]);
        respond(201, ['id' => (int) $pdo->lastInsertId()]);
    }

    if ($resource === 'bookings' && $method === 'PATCH') {
        require_csrf();
        $user = require_user($pdo, ['worker']);
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            respond(400, ['error' => 'A valid booking id is required.']);
        }
        $data = read_json();
        $status = $data['status'] ?? '';
        $statuses = ['confirmed', 'declined', 'completed'];
        if (!in_array($status, $statuses, true)) {
            respond(422, ['error' => 'Select a valid booking status.']);
        }
        $check = $pdo->prepare(
            'SELECT b.status
             FROM bookings b
             INNER JOIN workers w ON w.id = b.worker_id
             WHERE b.id = ? AND w.user_id = ?'
        );
        $check->execute([$id, $user['id']]);
        $currentStatus = $check->fetchColumn();
        if ($currentStatus === false) {
            respond(404, ['error' => 'Booking not found for this fundi account.']);
        }
        $transitions = [
            'pending' => ['confirmed', 'declined'],
            'confirmed' => ['completed'],
            'declined' => [],
            'completed' => [],
        ];
        if (!in_array($status, $transitions[$currentStatus], true)) {
            respond(409, ['error' => 'That booking status change is not allowed.']);
        }
        $statement = $pdo->prepare('UPDATE bookings SET status = ? WHERE id = ?');
        $statement->execute([$status, $id]);
        respond(200, ['ok' => true]);
    }

    if ($resource === 'reviews' && $method === 'POST') {
        require_csrf();
        $user = require_user($pdo, ['client']);
        $data = read_json();
        $bookingId = (int) required_number($data, 'bookingId', 1, PHP_INT_MAX);
        $stars = (int) required_number($data, 'stars', 1, 5);
        $comment = isset($data['comment']) && is_string($data['comment']) ? trim($data['comment']) : '';
        if (mb_strlen($comment) > 2000) {
            respond(422, ['error' => 'Review comment is too long.']);
        }

        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            'SELECT worker_id, status, rated
             FROM bookings WHERE id = ? AND client_id = ? FOR UPDATE'
        );
        $statement->execute([$bookingId, $user['id']]);
        $booking = $statement->fetch();
        if (!$booking) {
            $pdo->rollBack();
            respond(404, ['error' => 'Booking not found for this customer account.']);
        }
        if ($booking['status'] !== 'completed' || (bool) $booking['rated']) {
            $pdo->rollBack();
            respond(409, ['error' => 'Only completed, unrated bookings can be reviewed.']);
        }
        $insert = $pdo->prepare('INSERT INTO reviews (worker_id, booking_id, stars, comment) VALUES (?, ?, ?, ?)');
        $insert->execute([(int) $booking['worker_id'], $bookingId, $stars, $comment ?: 'Great service.']);
        $update = $pdo->prepare('UPDATE bookings SET rated = 1 WHERE id = ?');
        $update->execute([$bookingId]);
        $pdo->commit();
        respond(201, ['ok' => true]);
    }

    respond(404, ['error' => 'Unknown API resource or method.']);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('FundiLink API request failed: ' . $exception->getMessage());
    respond(500, ['error' => 'The request could not be completed. Check the PHP error log for details.']);
}
