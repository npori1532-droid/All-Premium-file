<?php
// =============================================
//  db.php - Database Configuration & Helpers
// =============================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'xadminbd905_afruza');
define('DB_USER', 'xadminbd905_afruza');
define('DB_PASS', 'xadminbd905_afruza');

define('SITE_NAME', 'Fast Cash');
define('SITE_SLOGAN', 'দ্রত টাকা 💰 ✓');
define('CURRENCY', '৳');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

function initDB() {
    $db = getDB();

    // Users table
    $db->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `telegram_id`   BIGINT UNIQUE NOT NULL,
        `username`      VARCHAR(255) DEFAULT NULL,
        `first_name`    VARCHAR(255) DEFAULT NULL,
        `last_name`     VARCHAR(255) DEFAULT NULL,
        `photo_url`     TEXT DEFAULT NULL,
        `balance`       DECIMAL(12,2) DEFAULT 0.00,
        `bonus_balance` DECIMAL(12,2) DEFAULT 0.00,
        `referral_code` VARCHAR(20) UNIQUE,
        `referred_by`   INT DEFAULT NULL,
        `ads_watched`   INT DEFAULT 0,
        `is_banned`     TINYINT(1) DEFAULT 0,
        `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_telegram_id` (`telegram_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Tasks table
    $db->exec("CREATE TABLE IF NOT EXISTS `tasks` (
        `id`               INT AUTO_INCREMENT PRIMARY KEY,
        `name`             VARCHAR(255) NOT NULL,
        `link`             TEXT NOT NULL,
        `logo`             TEXT NOT NULL,
        `points`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `type`             ENUM('telegram','general') DEFAULT 'general',
        `channel_username` VARCHAR(255) DEFAULT NULL,
        `is_active`        TINYINT(1) DEFAULT 1,
        `sort_order`       INT DEFAULT 0,
        `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // User tasks (completed tasks)
    $db->exec("CREATE TABLE IF NOT EXISTS `user_tasks` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `user_id`      INT NOT NULL,
        `task_id`      INT NOT NULL,
        `completed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_user_task` (`user_id`, `task_id`),
        INDEX `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Withdrawals
    $db->exec("CREATE TABLE IF NOT EXISTS `withdrawals` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `user_id`    INT NOT NULL,
        `method`     VARCHAR(100) NOT NULL,
        `account`    VARCHAR(255) NOT NULL,
        `amount`     DECIMAL(12,2) NOT NULL,
        `status`     ENUM('pending','approved','rejected') DEFAULT 'pending',
        `admin_note` TEXT DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_user_id` (`user_id`),
        INDEX `idx_status`  (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Transactions
    $db->exec("CREATE TABLE IF NOT EXISTS `transactions` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `user_id`     INT NOT NULL,
        `type`        VARCHAR(100) NOT NULL,
        `amount`      DECIMAL(12,2) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Settings
    $db->exec("CREATE TABLE IF NOT EXISTS `settings` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `setting_key`   VARCHAR(100) UNIQUE NOT NULL,
        `setting_value` TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Referrals
    $db->exec("CREATE TABLE IF NOT EXISTS `referrals` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `referrer_id` INT NOT NULL,
        `referred_id` INT NOT NULL UNIQUE,
        `bonus_paid`  TINYINT(1) DEFAULT 0,
        `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_referrer` (`referrer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Insert default settings
    $defaults = [
        'bot_token'        => '',
        'bot_username'     => 'FastCashBD1bot',
        'marquee_text'     => '🎉 Fast Cash এ স্বাগতম! টাস্ক করুন এবং দ্রুত টাকা উপার্জন করুন! 💰',
        'referral_bonus'   => '100',
        'min_withdrawal'   => '1100',
        'ads_required'     => '10',
        'support_username' => '',
        'tutorial_video'   => '',
        'admin_password'   => password_hash('admin123', PASSWORD_DEFAULT),
        'vote_username'    => '',
    ];

    $stmt = $db->prepare("INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES (?, ?)");
    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
    }
}

// ── Helpers ──────────────────────────────────

function getSetting(string $key): ?string {
    static $cache = [];
    if (!isset($cache[$key])) {
        $db   = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row         = $stmt->fetch();
        $cache[$key] = $row ? $row['setting_value'] : null;
    }
    return $cache[$key];
}

function setSetting(string $key, string $value): void {
    $db   = getDB();
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");
    $stmt->execute([$key, $value, $value]);
}

function generateReferralCode(): string {
    do {
        $code = strtoupper(substr(md5(uniqid((string)rand(), true)), 0, 8));
        $db   = getDB();
        $stmt = $db->prepare("SELECT id FROM users WHERE referral_code = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    return $code;
}

function getUserByTelegramId(int $tgId): ?array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $stmt->execute([$tgId]);
    return $stmt->fetch() ?: null;
}

function createOrUpdateUser(array $tgUser, ?string $refCode = null): array {
    $db     = getDB();
    $tgId   = (int) $tgUser['id'];
    $user   = getUserByTelegramId($tgId);

    if ($user) {
        // Update profile fields
        $stmt = $db->prepare("UPDATE users SET username=?, first_name=?, last_name=?, photo_url=? WHERE telegram_id=?");
        $stmt->execute([
            $tgUser['username']   ?? null,
            $tgUser['first_name'] ?? null,
            $tgUser['last_name']  ?? null,
            $tgUser['photo_url']  ?? null,
            $tgId,
        ]);
        return getUserByTelegramId($tgId);
    }

    // New user
    $code = generateReferralCode();
    $referredById = null;

    if ($refCode) {
        $stmt = $db->prepare("SELECT id FROM users WHERE referral_code = ?");
        $stmt->execute([$refCode]);
        $referrer = $stmt->fetch();
        if ($referrer) {
            $referredById = $referrer['id'];
        }
    }

    $stmt = $db->prepare("INSERT INTO users (telegram_id, username, first_name, last_name, photo_url, referral_code, referred_by)
                          VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([
        $tgId,
        $tgUser['username']   ?? null,
        $tgUser['first_name'] ?? null,
        $tgUser['last_name']  ?? null,
        $tgUser['photo_url']  ?? null,
        $code,
        $referredById,
    ]);
    $newUserId = $db->lastInsertId();

    // Handle referral bonus
    if ($referredById) {
        $bonus = (float) (getSetting('referral_bonus') ?? 100);
        $db->prepare("INSERT INTO referrals (referrer_id, referred_id, bonus_paid) VALUES (?,?,1)")->execute([$referredById, $newUserId]);
        $db->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$bonus, $referredById]);
        $db->prepare("INSERT INTO transactions (user_id, type, amount, description) VALUES (?,?,?,?)")->execute([
            $referredById, 'referral_bonus', $bonus, 'রেফার বোনাস - নতুন ইউজার যোগ দিয়েছে'
        ]);
    }

    return getUserByTelegramId($tgId);
}

function addTransaction(int $userId, string $type, float $amount, string $desc): void {
    $db   = getDB();
    $stmt = $db->prepare("INSERT INTO transactions (user_id, type, amount, description) VALUES (?,?,?,?)");
    $stmt->execute([$userId, $type, $amount, $desc]);
}

// Validate Telegram initData
function validateTelegramInitData(string $initData, string $botToken): bool {
    if (empty($initData) || empty($botToken)) return false;
    $data = [];
    parse_str($initData, $data);
    $hash = $data['hash'] ?? '';
    unset($data['hash']);
    ksort($data);
    $dataCheckString = implode("\n", array_map(fn($k, $v) => "$k=$v", array_keys($data), array_values($data)));
    $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $computedHash = hash_hmac('sha256', $dataCheckString, $secretKey);
    return hash_equals($computedHash, $hash);
}

// Check Telegram channel membership via Bot API
function checkTelegramMembership(int $userId, string $channelUsername): bool {
    $botToken = getSetting('bot_token');
    if (!$botToken) return false;
    $url = "https://api.telegram.org/bot{$botToken}/getChatMember?chat_id=@{$channelUsername}&user_id={$userId}";
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $res = @file_get_contents($url, false, $ctx);
    if (!$res) return false;
    $json = json_decode($res, true);
    if (!$json || !$json['ok']) return false;
    $status = $json['result']['status'] ?? '';
    return in_array($status, ['member', 'administrator', 'creator'], true);
}

// Initialize on include
initDB();
