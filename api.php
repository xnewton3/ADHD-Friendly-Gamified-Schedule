<?php

// Load environment variables from /var/www/.env – NO FALLBACK
$envFile = '/var/www/.env';
if (!file_exists($envFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Missing .env file']);
    exit;
}

$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0) continue;
    $parts = explode('=', $line, 2);
    if (count($parts) === 2) {
        $key = trim($parts[0]);
        $value = trim($parts[1]);
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

// Define constants – crash if missing (no fallback)
if (!getenv('PASSKEY_CREATION_KEY')) {
    http_response_code(500);
    echo json_encode(['error' => 'PASSKEY_CREATION_KEY not set in .env']);
    exit;
}
if (!getenv('STATS_ACCESS_CODE')) {
    http_response_code(500);
    echo json_encode(['error' => 'STATS_ACCESS_CODE not set in .env']);
    exit;
}
define('PASSKEY_CREATION_KEY', getenv('PASSKEY_CREATION_KEY'));
define('STATS_ACCESS_CODE', getenv('STATS_ACCESS_CODE'));

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, X-Stats-Access-Code');

define('DATA_DIR', __DIR__ . '/data');
define('DB_FILE', DATA_DIR . '/data.db');

// Create data directory if it doesn't exist
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// Initialize database and tables
function init_database() {
    $db = new SQLite3(DB_FILE);

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id TEXT PRIMARY KEY,
        username TEXT NOT NULL,
        avatar TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS user_settings (
        user_id TEXT PRIMARY KEY,
        small_reward_points INTEGER DEFAULT 10,
        big_reward_points INTEGER DEFAULT 25,
        theme TEXT DEFAULT 'dark',
        notifications_enabled INTEGER DEFAULT 0,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS quests (
        id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        name TEXT NOT NULL,
        type TEXT CHECK(type IN ('main', 'side', 'calendar')) NOT NULL,
        points INTEGER DEFAULT 1,
        schedule TEXT,
        reminder_time TEXT,
        days_visible TEXT,
        specific_dates TEXT,
        group_name TEXT,
        group_step INTEGER,
        from_calendar INTEGER DEFAULT 0,
        calendar_feed_id TEXT,
        expires DATE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS quest_completions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        quest_id TEXT NOT NULL,
        user_id TEXT NOT NULL,
        completion_date DATE NOT NULL,
        completed INTEGER DEFAULT 0,
        points_earned INTEGER DEFAULT 0,
        UNIQUE(quest_id, completion_date),
        FOREIGN KEY (quest_id) REFERENCES quests(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        type TEXT CHECK(type IN ('quest_complete', 'reward_redeem', 'quest_undo', 'bonus', 'achievement')) NOT NULL,
        description TEXT NOT NULL,
        points INTEGER NOT NULL,
        date DATE NOT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS shop_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        name TEXT NOT NULL,
        cost INTEGER NOT NULL,
        is_limited INTEGER DEFAULT 0,
        expires_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS achievements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT NOT NULL,
        requirement_type TEXT NOT NULL,
        requirement_value INTEGER NOT NULL,
        points_reward INTEGER DEFAULT 0,
        icon TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS user_achievements (
        user_id TEXT NOT NULL,
        achievement_id INTEGER NOT NULL,
        earned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, achievement_id),
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (achievement_id) REFERENCES achievements(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS calendar_feeds (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        name TEXT NOT NULL,
        url TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // Insert default achievements if none exist
    $existing = $db->querySingle("SELECT COUNT(*) FROM achievements");
    if ($existing == 0) {
        $achievements = [
            ['First Quest', 'Complete your first quest', 'quest_count', 1, 5, '🎯'],
            ['Quest Novice', 'Complete 10 quests', 'quest_count', 10, 10, '🌟'],
            ['Quest Master', 'Complete 100 quests', 'quest_count', 100, 50, '🏆'],
            ['Quest Legend', 'Complete 500 quests', 'quest_count', 500, 100, '👑'],
            ['7 Day Streak', 'Complete quests for 7 days in a row', 'streak', 7, 20, '🔥'],
            ['30 Day Streak', 'Complete quests for 30 days in a row', 'streak', 30, 50, '⚡'],
            ['365 Day Streak', 'Complete quests for a full year', 'streak', 365, 200, '💪'],
            ['Hydration Hero', 'Complete lemonade/water quest for 7 days', 'lemonade_days', 7, 15, '💧'],
            ['Hydration Master', 'Complete lemonade/water quest for 30 days', 'lemonade_days', 30, 40, '🥤'],
            ['Hydration Legend', 'Complete lemonade/water quest for 365 days', 'lemonade_days', 365, 150, '🌊'],
            ['Early Bird', 'Complete 10 quests before 9 AM', 'morning_quests', 10, 25, '🌅'],
            ['Night Owl', 'Complete 10 quests after 10 PM', 'night_quests', 10, 25, '🌙'],
        ];

        $stmt = $db->prepare("INSERT INTO achievements (name, description, requirement_type, requirement_value, points_reward, icon) VALUES (:name, :description, :req_type, :req_value, :points, :icon)");
        foreach ($achievements as $ach) {
            $stmt->bindValue(':name', $ach[0], SQLITE3_TEXT);
            $stmt->bindValue(':description', $ach[1], SQLITE3_TEXT);
            $stmt->bindValue(':req_type', $ach[2], SQLITE3_TEXT);
            $stmt->bindValue(':req_value', $ach[3], SQLITE3_INTEGER);
            $stmt->bindValue(':points', $ach[4], SQLITE3_INTEGER);
            $stmt->bindValue(':icon', $ach[5], SQLITE3_TEXT);
            $stmt->execute();
        }
    }

    // Add last_reset column to users table if not exists
    $columns = $db->query("PRAGMA table_info(users)");
    $hasLastReset = false;
    while ($col = $columns->fetchArray(SQLITE3_ASSOC)) {
        if ($col['name'] === 'last_reset') {
            $hasLastReset = true;
            break;
        }
    }
    if (!$hasLastReset) {
        $db->exec("ALTER TABLE users ADD COLUMN last_reset TEXT DEFAULT NULL");
    }

    $db->close();
}

// Run initialization
init_database();

// Start session for password login persistence
session_start();

// Helper function to check if user is logged in via password
function isLoggedIn() {
    if (isset($_SESSION['password_verified']) && $_SESSION['password_verified'] === true) {
        return true;
    }
    if (isset($_COOKIE['password_verified']) && $_COOKIE['password_verified'] === '1') {
        $_SESSION['password_verified'] = true;
        return true;
    }
    return false;
}

// Helper function to check if stats access code is provided and valid
function isStatsAuthorized() {
    $headers = getallheaders();
    $providedCode = $headers['X-Stats-Access-Code'] ?? '';
    return $providedCode === STATS_ACCESS_CODE;
}

// Helper to get single user ID (always same)
function get_user_id() {
    return 'single_user';
}

function get_db() {
    return new SQLite3(DB_FILE);
}

$action = $_GET['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

// Public endpoints (no auth of any kind required)
$public_actions = ['test', 'login_status', 'verify_password', 'logout', 'stats_access_code', 'ical_proxy', 'get_theme'];

// Stats endpoints: can be accessed via stats code header (GET only) OR normal login
$stats_actions = ['', 'history', 'history_stats', 'points', 'today_bonus_points', 'completions', 'achievements', 'shop_items', 'calendar_feeds', 'calendar_quests', 'avatar', 'last_reset'];

// Authentication logic
if (!in_array($action, $public_actions)) {
    // Allow GET requests with valid stats code
    if ($method === 'GET' && in_array($action, $stats_actions) && isStatsAuthorized()) {
        // Stats code granted – continue
    }
    // Otherwise require normal login
    elseif (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized – please login or provide valid stats access code']);
        exit;
    }
}

// ============ TEST ENDPOINT ============
if ($method === 'GET' && $action === 'test') {
    echo json_encode(['ok' => true, 'php_version' => phpversion(), 'db' => DB_FILE]);
    exit;
}

// ============ PASSWORD LOGIN ENDPOINTS ============

// GET login status – returns whether user is already logged in
if ($method === 'GET' && $action === 'login_status') {
    echo json_encode(['verified' => isLoggedIn()]);
    exit;
}

// POST verify password – checks against PASSKEY_CREATION_KEY and sets session + cookie
if ($method === 'POST' && $action === 'verify_password') {
    $data = json_decode(file_get_contents('php://input'), true);
    $password = $data['password'] ?? '';

    if ($password === PASSKEY_CREATION_KEY) {
        $_SESSION['password_verified'] = true;
        // Set a cookie that lasts 3 weeks (21 days) – renewed on each page load
        setcookie('password_verified', '1', time() + (21 * 24 * 3600), '/', '', false, true);
        echo json_encode(['success' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid password']);
    }
    exit;
}

// POST logout – clears session and cookie
if ($method === 'POST' && $action === 'logout') {
    $_SESSION['password_verified'] = false;
    setcookie('password_verified', '', time() - 3600, '/');
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

// ============ USER ENDPOINTS ============

// GET user data (main dashboard data)
if ($method === 'GET' && !$action) {
    $db = get_db();
    $userId = get_user_id();

    // Get or create user
    $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->bindValue(':id', $userId, SQLITE3_TEXT);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$user) {
        $stmt = $db->prepare("INSERT INTO users (id, username) VALUES (:id, 'Player 1')");
        $stmt->bindValue(':id', $userId, SQLITE3_TEXT);
        $stmt->execute();

        $stmt = $db->prepare("INSERT INTO user_settings (user_id) VALUES (:id)");
        $stmt->bindValue(':id', $userId, SQLITE3_TEXT);
        $stmt->execute();

        $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->bindValue(':id', $userId, SQLITE3_TEXT);
        $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

    // Get settings
    $stmt = $db->prepare("SELECT * FROM user_settings WHERE user_id = :id");
    $stmt->bindValue(':id', $userId, SQLITE3_TEXT);
    $settings = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    // Get quests
    $stmt = $db->prepare("SELECT * FROM quests WHERE user_id = :id ORDER BY created_at");
    $stmt->bindValue(':id', $userId, SQLITE3_TEXT);
    $questsResult = $stmt->execute();
    $quests = [];
    while ($row = $questsResult->fetchArray(SQLITE3_ASSOC)) {
        if ($row['days_visible']) $row['days_visible'] = json_decode($row['days_visible'], true);
        if ($row['specific_dates']) $row['specific_dates'] = json_decode($row['specific_dates'], true);
        $quests[] = $row;
    }

    // Get shop items
    $stmt = $db->prepare("SELECT * FROM shop_items WHERE user_id = :id");
    $stmt->bindValue(':id', $userId, SQLITE3_TEXT);
    $shopResult = $stmt->execute();
    $shopItems = [];
    while ($row = $shopResult->fetchArray(SQLITE3_ASSOC)) {
        $shopItems[] = $row;
    }

    // Get calendar feeds
    $stmt = $db->prepare("SELECT * FROM calendar_feeds WHERE user_id = :id");
    $stmt->bindValue(':id', $userId, SQLITE3_TEXT);
    $feedsResult = $stmt->execute();
    $calendarFeeds = [];
    while ($row = $feedsResult->fetchArray(SQLITE3_ASSOC)) {
        $calendarFeeds[] = $row;
    }

    $db->close();

    echo json_encode([
        'user' => $user,
        'settings' => $settings,
        'quests' => $quests,
        'shopItems' => $shopItems,
        'calendarFeeds' => $calendarFeeds
    ]);
    exit;
}

// ============ POINTS CALCULATION ============

// GET points – total and today's earned points
if ($method === 'GET' && $action === 'points') {
    $db = get_db();
    $userId = get_user_id();

    $result = $db->querySingle("SELECT SUM(CAST(points AS INTEGER)) as total FROM history WHERE user_id = '$userId'", true);
    $totalPoints = $result['total'] ?? 0;

    $today = date('Y-m-d');
    $result = $db->querySingle("SELECT SUM(CAST(points AS INTEGER)) as today_total FROM history WHERE user_id = '$userId' AND date = '$today' AND CAST(points AS INTEGER) > 0", true);
    $todayPoints = $result['today_total'] ?? 0;

    $db->close();
    echo json_encode(['totalPoints' => $totalPoints, 'todayPoints' => $todayPoints]);
    exit;
}

// GET today's bonus points (from history where type='bonus' and date = today)
if ($method === 'GET' && $action === 'today_bonus_points') {
    $db = get_db();
    $userId = get_user_id();
    $result = $db->query("SELECT points, date FROM history WHERE user_id = '$userId' AND type = 'bonus'");
    $todayBonus = 0;
    $todayTimestamp = strtotime(date('Y-m-d'));
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $storedDate = $row['date'];
        $storedTimestamp = strtotime($storedDate);
        if ($storedTimestamp !== false && $storedTimestamp === $todayTimestamp) {
            $todayBonus += (int)$row['points'];
        }
    }
    $db->close();
    echo json_encode(['todayBonus' => $todayBonus]);
    exit;
}

// ============ QUEST COMPLETIONS ============

// GET completions for a specific date (default today)
if ($method === 'GET' && $action === 'completions') {
    $db = get_db();
    $userId = get_user_id();
    $dateParam = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
        $dateParam = date('Y-m-d');
    }
    $stmt = $db->prepare("SELECT quest_id, completed, points_earned FROM quest_completions WHERE user_id = :user AND completion_date = :date");
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $stmt->bindValue(':date', $dateParam, SQLITE3_TEXT);
    $result = $stmt->execute();
    $completions = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $completions[$row['quest_id']] = [
            'completed' => (bool)$row['completed'],
            'points_earned' => $row['points_earned']
        ];
    }
    $db->close();
    echo json_encode(['completions' => $completions]);
    exit;
}

// POST toggle quest completion – marks a quest as done/undone and updates history
if ($method === 'POST' && $action === 'toggle_quest') {
    $data = json_decode(file_get_contents('php://input'), true);
    $db = get_db();
    $userId = get_user_id();
    $today = date('Y-m-d');

    $questId = $data['quest_id'];
    $completed = $data['completed'] ? 1 : 0;
    $points = $data['points'] ?? 1;
    $questName = $data['quest_name'] ?? '';

    // Check if completion exists
    $stmt = $db->prepare("SELECT id FROM quest_completions WHERE quest_id = :quest AND user_id = :user AND completion_date = :date");
    $stmt->bindValue(':quest', $questId, SQLITE3_TEXT);
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $stmt->bindValue(':date', $today, SQLITE3_TEXT);
    $existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($existing) {
        $stmt = $db->prepare("UPDATE quest_completions SET completed = :completed, points_earned = :points WHERE id = :id");
        $stmt->bindValue(':completed', $completed, SQLITE3_INTEGER);
        $stmt->bindValue(':points', $completed ? $points : 0, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $existing['id'], SQLITE3_INTEGER);
        $stmt->execute();
    } else {
        $stmt = $db->prepare("INSERT INTO quest_completions (quest_id, user_id, completion_date, completed, points_earned) VALUES (:quest, :user, :date, :completed, :points)");
        $stmt->bindValue(':quest', $questId, SQLITE3_TEXT);
        $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
        $stmt->bindValue(':date', $today, SQLITE3_TEXT);
        $stmt->bindValue(':completed', $completed, SQLITE3_INTEGER);
        $stmt->bindValue(':points', $completed ? $points : 0, SQLITE3_INTEGER);
        $stmt->execute();
    }

    // Add to history if completed
    if ($completed) {
        $stmt = $db->prepare("INSERT INTO history (user_id, type, description, points, date) VALUES (:user, 'quest_complete', :desc, :points, :date)");
        $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
        $stmt->bindValue(':desc', "Completed quest: " . str_replace("'", "''", $questName), SQLITE3_TEXT);
        $stmt->bindValue(':points', $points, SQLITE3_INTEGER);
        $stmt->bindValue(':date', $today, SQLITE3_TEXT);
        $stmt->execute();
    } else {
        // Remove from history if uncompleted
        $stmt = $db->prepare("DELETE FROM history WHERE user_id = :user AND type = 'quest_complete' AND description = :desc AND date = :date ORDER BY id DESC LIMIT 1");
        $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
        $stmt->bindValue(':desc', "Completed quest: " . str_replace("'", "''", $questName), SQLITE3_TEXT);
        $stmt->bindValue(':date', $today, SQLITE3_TEXT);
        $stmt->execute();
    }

    if ($completed && isset($data['was_missed']) && $data['was_missed'] === true) {
        // Award bonus for completing a missed quest
        $stmt = $db->prepare("INSERT INTO history (user_id, type, description, points, date) VALUES (:user, 'bonus', :desc, :points, :date)");
        $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
        $stmt->bindValue(':desc', "Bonus for completing missed quest: " . str_replace("'", "''", $questName), SQLITE3_TEXT);
        $stmt->bindValue(':points', 1, SQLITE3_INTEGER);
        $stmt->bindValue(':date', $today, SQLITE3_TEXT);
        $stmt->execute();
    }

    check_achievements($db, $userId);
    $db->close();
    echo json_encode(['ok' => true, 'completed' => (bool)$completed]);
    exit;
}

// POST add bonus points for missed quests (bulk)
if ($method === 'POST' && $action === 'add_bonus') {
    $data = json_decode(file_get_contents('php://input'), true);
    $db = get_db();
    $userId = get_user_id();
    $today = date('Y-m-d');
    $bonusPoints = $data['points'] ?? 0;
    $missedCount = $data['count'] ?? 0;

    if ($bonusPoints > 0) {
        $stmt = $db->prepare("INSERT INTO history (user_id, type, description, points, date) VALUES (:user, 'bonus', :desc, :points, :date)");
        $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
        $stmt->bindValue(':desc', "Bonus for $missedCount missed quest" . ($missedCount != 1 ? 's' : ''), SQLITE3_TEXT);
        $stmt->bindValue(':points', $bonusPoints, SQLITE3_INTEGER);
        $stmt->bindValue(':date', $today, SQLITE3_TEXT);
        $stmt->execute();
    }

    $db->close();
    echo json_encode(['ok' => true]);
    exit;
}

// ============ UPDATE SETTINGS ============
if ($method === 'POST' && $action === 'update_settings') {
    $data = json_decode(file_get_contents('php://input'), true);
    $db = get_db();
    $userId = get_user_id();

    $small = $data['small_reward_points'] ?? 10;
    $big = $data['big_reward_points'] ?? 25;

    $stmt = $db->prepare("UPDATE user_settings SET small_reward_points = :small, big_reward_points = :big WHERE user_id = :user");
    $stmt->bindValue(':small', $small, SQLITE3_INTEGER);
    $stmt->bindValue(':big', $big, SQLITE3_INTEGER);
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $stmt->execute();

    $db->close();
    echo json_encode(['ok' => true]);
    exit;
}

// ============ SHOP ENDPOINTS ============

// GET shop items
if ($method === 'GET' && $action === 'shop_items') {
    $db = get_db();
    $userId = get_user_id();

    $stmt = $db->prepare("SELECT * FROM shop_items WHERE user_id = :user ORDER BY created_at");
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $result = $stmt->execute();

    $items = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $items[] = $row;
    }

    $db->close();
    echo json_encode(['items' => $items]);
    exit;
}

// POST add shop item
if ($method === 'POST' && $action === 'add_shop_item') {
    $data = json_decode(file_get_contents('php://input'), true);
    $db = get_db();
    $userId = get_user_id();

    $stmt = $db->prepare("INSERT INTO shop_items (user_id, name, cost) VALUES (:user, :name, :cost)");
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $stmt->bindValue(':name', $data['name'], SQLITE3_TEXT);
    $stmt->bindValue(':cost', $data['cost'], SQLITE3_INTEGER);
    $stmt->execute();

    $db->close();
    echo json_encode(['ok' => true]);
    exit;
}

// POST update shop item
if ($method === 'POST' && $action === 'update_shop_item') {
    $data = json_decode(file_get_contents('php://input'), true);
    $db = get_db();
    $userId = get_user_id();

    $stmt = $db->prepare("UPDATE shop_items SET name = :name, cost = :cost WHERE id = :id AND user_id = :user");
    $stmt->bindValue(':name', $data['name'], SQLITE3_TEXT);
    $stmt->bindValue(':cost', $data['cost'], SQLITE3_INTEGER);
    $stmt->bindValue(':id', $data['id'], SQLITE3_INTEGER);
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $stmt->execute();

    $db->close();
    echo json_encode(['ok' => true]);
    exit;
}

// DELETE shop item
if ($method === 'DELETE' && $action === 'delete_shop_item') {
    $id = $_GET['id'] ?? 0;
    $db = get_db();
    $userId = get_user_id();

    $stmt = $db->prepare("DELETE FROM shop_items WHERE id = :id AND user_id = :user");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $stmt->execute();

    $db->close();
    echo json_encode(['ok' => true]);
    exit;
}

// POST redeem reward – deduct points and log redemption
if ($method === 'POST' && $action === 'redeem') {
    $data = json_decode(file_get_contents('php://input'), true);
    $db = get_db();
    $userId = get_user_id();
    $today = date('Y-m-d');

    $itemName = $data['name'];
    $cost = $data['cost'];

    $result = $db->querySingle("SELECT SUM(CAST(points AS INTEGER)) as total FROM history WHERE user_id = '$userId'", true);
    $balance = $result['total'] ?? 0;

    if ($balance >= $cost) {
        $stmt = $db->prepare("INSERT INTO history (user_id, type, description, points, date) VALUES (:user, 'reward_redeem', :desc, :points, :date)");
        $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
        $stmt->bindValue(':desc', "Redeemed $itemName", SQLITE3_TEXT);
        $stmt->bindValue(':points', -$cost, SQLITE3_INTEGER);
        $stmt->bindValue(':date', $today, SQLITE3_TEXT);
        $stmt->execute();

        $db->close();
        echo json_encode(['ok' => true, 'new_balance' => $balance - $cost]);
    } else {
        $db->close();
        echo json_encode(['ok' => false, 'error' => 'Insufficient points']);
    }
    exit;
}

// ============ ACHIEVEMENTS ============

// GET achievements – list all and which ones are earned
if ($method === 'GET' && $action === 'achievements') {
    $db = get_db();
    $userId = get_user_id();

    $achievementsResult = $db->query("SELECT * FROM achievements ORDER BY requirement_value");
    $allAchievements = [];
    while ($row = $achievementsResult->fetchArray(SQLITE3_ASSOC)) {
        $allAchievements[] = $row;
    }

    $stmt = $db->prepare("SELECT achievement_id FROM user_achievements WHERE user_id = :user");
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $earnedResult = $stmt->execute();
    $earnedIds = [];
    while ($row = $earnedResult->fetchArray(SQLITE3_ASSOC)) {
        $earnedIds[] = $row['achievement_id'];
    }

    $db->close();
    echo json_encode([
        'all' => $allAchievements,
        'earned' => $earnedIds
    ]);
    exit;
}

// Helper function to check and award achievements
function check_achievements($db, $userId) {
    $questCount = $db->querySingle("SELECT COUNT(*) FROM history WHERE user_id = '$userId' AND type = 'quest_complete'");

    $streakResult = $db->query("SELECT DISTINCT date FROM history WHERE user_id = '$userId' AND type = 'quest_complete' ORDER BY date DESC");
    $streak = 0;
    $lastDate = null;
    while ($row = $streakResult->fetchArray(SQLITE3_ASSOC)) {
        $currentDate = new DateTime($row['date']);
        if ($lastDate === null) {
            $streak = 1;
        } else {
            $diff = $lastDate->diff($currentDate)->days;
            if ($diff == 1) {
                $streak++;
            } else {
                break;
            }
        }
        $lastDate = $currentDate;
    }

    $lemonadeCount = $db->querySingle("SELECT COUNT(DISTINCT date) FROM history WHERE user_id = '$userId' AND type = 'quest_complete' AND (description LIKE '%lemonade%' OR description LIKE '%water%')");
    $morningCount = $db->querySingle("SELECT COUNT(*) FROM history WHERE user_id = '$userId' AND type = 'quest_complete' AND time(timestamp) < '09:00:00'");
    $nightCount = $db->querySingle("SELECT COUNT(*) FROM history WHERE user_id = '$userId' AND type = 'quest_complete' AND time(timestamp) > '22:00:00'");

    $achievementsResult = $db->query("SELECT * FROM achievements");
    while ($ach = $achievementsResult->fetchArray(SQLITE3_ASSOC)) {
        $check = $db->querySingle("SELECT COUNT(*) FROM user_achievements WHERE user_id = '$userId' AND achievement_id = {$ach['id']}");
        if ($check > 0) continue;

        $earned = false;
        switch ($ach['requirement_type']) {
            case 'quest_count':
                if ($questCount >= $ach['requirement_value']) $earned = true;
                break;
            case 'streak':
                if ($streak >= $ach['requirement_value']) $earned = true;
                break;
            case 'lemonade_days':
                if ($lemonadeCount >= $ach['requirement_value']) $earned = true;
                break;
            case 'morning_quests':
                if ($morningCount >= $ach['requirement_value']) $earned = true;
                break;
            case 'night_quests':
                if ($nightCount >= $ach['requirement_value']) $earned = true;
                break;
        }

        if ($earned) {
            $stmt = $db->prepare("INSERT INTO user_achievements (user_id, achievement_id) VALUES (:user, :ach)");
            $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
            $stmt->bindValue(':ach', $ach['id'], SQLITE3_INTEGER);
            $stmt->execute();

            if ($ach['points_reward'] > 0) {
                $today = date('Y-m-d');
                $stmt = $db->prepare("INSERT INTO history (user_id, type, description, points, date) VALUES (:user, 'achievement', :desc, :points, :date)");
                $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
                $stmt->bindValue(':desc', "Achievement Unlocked: {$ach['name']}", SQLITE3_TEXT);
                $stmt->bindValue(':points', $ach['points_reward'], SQLITE3_INTEGER);
                $stmt->bindValue(':date', $today, SQLITE3_TEXT);
                $stmt->execute();
            }
        }
    }
}

// ============ CALENDAR FEEDS ============

// GET calendar feeds
if ($method === 'GET' && $action === 'calendar_feeds') {
    $db = get_db();
    $userId = get_user_id();

    $stmt = $db->prepare("SELECT * FROM calendar_feeds WHERE user_id = :user");
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $result = $stmt->execute();

    $feeds = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $feeds[] = $row;
    }

    $db->close();
    echo json_encode(['feeds' => $feeds]);
    exit;
}

// POST add calendar feed
if ($method === 'POST' && $action === 'add_calendar_feed') {
    $data = json_decode(file_get_contents('php://input'), true);
    $db = get_db();
    $userId = get_user_id();

    $stmt = $db->prepare("INSERT INTO calendar_feeds (user_id, name, url) VALUES (:user, :name, :url)");
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $stmt->bindValue(':name', $data['name'], SQLITE3_TEXT);
    $stmt->bindValue(':url', $data['url'], SQLITE3_TEXT);
    $stmt->execute();

    $db->close();
    echo json_encode(['ok' => true]);
    exit;
}

// DELETE calendar feed
if ($method === 'DELETE' && $action === 'delete_calendar_feed') {
    $id = $_GET['id'] ?? 0;
    $db = get_db();
    $userId = get_user_id();

    $stmt = $db->prepare("DELETE FROM calendar_feeds WHERE id = :id AND user_id = :user");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $stmt->execute();

    $db->close();
    echo json_encode(['ok' => true]);
    exit;
}

// ============ AVATAR ============

// GET avatar image (PNG)
if ($method === 'GET' && $action === 'avatar') {
    $avatarFile = DATA_DIR . '/avatar.png';
    if (file_exists($avatarFile)) {
        header('Content-Type: image/png');
        readfile($avatarFile);
    } else {
        http_response_code(404);
    }
    exit;
}

// POST upload new avatar
if ($method === 'POST' && $action === 'avatar') {
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        move_uploaded_file($_FILES['avatar']['tmp_name'], DATA_DIR . '/avatar.png');
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Upload failed']);
    }
    exit;
}

// ============ HISTORY ============

// GET full history log
if ($method === 'GET' && $action === 'history') {
    $db = get_db();
    $userId = get_user_id();

    $result = $db->query("SELECT id, type, description, points, date, timestamp FROM history WHERE user_id = '$userId' ORDER BY timestamp DESC");
    $history = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $history[] = $row;
    }

    $db->close();
    echo json_encode(['history' => $history]);
    exit;
}

// GET history statistics (for dashboard)
if ($method === 'GET' && $action === 'history_stats') {
    $db = get_db();
    $userId = get_user_id();

    $stats = [];

    $result = $db->querySingle("SELECT SUM(CAST(points AS INTEGER)) as total FROM history WHERE user_id = '$userId' AND CAST(points AS INTEGER) > 0", true);
    $stats['total_points_earned'] = $result['total'] ?? 0;

    $result = $db->querySingle("SELECT SUM(ABS(CAST(points AS INTEGER))) as total FROM history WHERE user_id = '$userId' AND CAST(points AS INTEGER) < 0", true);
    $stats['total_points_spent'] = $result['total'] ?? 0;

    $stats['net_points'] = $stats['total_points_earned'] - $stats['total_points_spent'];

    $result = $db->querySingle("SELECT COUNT(*) as count FROM history WHERE user_id = '$userId' AND type = 'reward_redeem'");
    $stats['total_rewards'] = $result['count'] ?? 0;

    $result = $db->querySingle("SELECT COUNT(*) as count FROM history WHERE user_id = '$userId' AND type = 'quest_complete'");
    $stats['total_quests'] = $result['count'] ?? 0;

    $result = $db->querySingle("SELECT date, SUM(CAST(points AS INTEGER)) as total FROM history WHERE user_id = '$userId' AND CAST(points AS INTEGER) > 0 GROUP BY date ORDER BY total DESC LIMIT 1", true);
    $stats['best_day'] = $result;

    $db->close();
    echo json_encode($stats);
    exit;
}

// ============ REMINDERS ============

// GET pending reminders (for browser notifications)
if ($method === 'GET' && $action === 'pending_reminders') {
    $db = get_db();
    $userId = get_user_id();
    $sentLog = DATA_DIR . '/sent_reminders_' . $userId . '.log';

    $now = new DateTime();
    $currentTime = $now->format('g:i a');
    $prepWindow = clone $now;
    $prepWindow->modify('+5 minutes');
    $prepTime = $prepWindow->format('g:i a');

    $today = date('Y-m-d');
    $sentReminders = [];
    if (file_exists($sentLog)) {
        $lines = file($sentLog, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            if (strpos($line, $today) === 0) {
                $sentReminders[] = explode('|', $line)[1];
            }
        }
    }

    $stmt = $db->prepare("SELECT * FROM quests WHERE user_id = :user AND reminder_time IS NOT NULL AND reminder_time != ''");
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $result = $stmt->execute();

    $pendingReminders = [];
    while ($quest = $result->fetchArray(SQLITE3_ASSOC)) {
        $checkStmt = $db->prepare("SELECT completed FROM quest_completions WHERE quest_id = :quest AND user_id = :user AND completion_date = :date");
        $checkStmt->bindValue(':quest', $quest['id'], SQLITE3_TEXT);
        $checkStmt->bindValue(':user', $userId, SQLITE3_TEXT);
        $checkStmt->bindValue(':date', $today, SQLITE3_TEXT);
        $completion = $checkStmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($completion && $completion['completed']) continue;

        $reminderTime = $quest['reminder_time'];
        $reminderKey = $quest['id'] . '_' . $reminderTime;

        if (in_array($reminderKey, $sentReminders)) continue;

        if ($reminderTime === $currentTime || $reminderTime === $prepTime) {
            $pendingReminders[] = [
                'id' => $quest['id'],
                'name' => $quest['name'],
                'points' => $quest['points'] ?? 1,
                'reminderTime' => $reminderTime
            ];

            $logEntry = $today . '|' . $reminderKey . "\n";
            file_put_contents($sentLog, $logEntry, FILE_APPEND);
        }
    }

    // Clean up old log entries
    if (file_exists($sentLog)) {
        $lines = file($sentLog, FILE_IGNORE_NEW_LINES);
        $weekAgo = date('Y-m-d', strtotime('-7 days'));
        $newLines = array_filter($lines, function($line) use ($weekAgo) {
            $date = explode('|', $line)[0];
            return $date >= $weekAgo;
        });
        file_put_contents($sentLog, implode("\n", $newLines) . ($newLines ? "\n" : ''));
    }

    $db->close();
    echo json_encode([
        'reminders' => $pendingReminders,
        'count' => count($pendingReminders)
    ]);
    exit;
}

// ============ QUEST MANAGEMENT ============

// POST update all non‑calendar quests (from manager)
if ($method === 'POST' && $action === 'update_quests') {
    $data = json_decode(file_get_contents('php://input'), true);
    $quests = $data['quests'] ?? [];
    $userId = get_user_id();

    $db = get_db();
    $db->exec("BEGIN TRANSACTION");

    $stmt = $db->prepare("DELETE FROM quests WHERE user_id = :user AND from_calendar = 0");
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $stmt->execute();

    $insert = $db->prepare("INSERT INTO quests (id, user_id, name, type, points, schedule, reminder_time, days_visible, specific_dates, group_name, group_step, from_calendar)
        VALUES (:id, :user, :name, :type, :points, :schedule, :reminder, :days_visible, :specific_dates, :group_name, :group_step, 0)");

    foreach ($quests as $quest) {
        if (!empty($quest['from_calendar'])) continue;

        $daysVisible = isset($quest['days_visible']) && is_array($quest['days_visible']) ? json_encode($quest['days_visible']) : null;
        $specificDates = isset($quest['specific_dates']) && is_array($quest['specific_dates']) ? json_encode($quest['specific_dates']) : null;

        $insert->bindValue(':id', $quest['id'], SQLITE3_TEXT);
        $insert->bindValue(':user', $userId, SQLITE3_TEXT);
        $insert->bindValue(':name', $quest['name'], SQLITE3_TEXT);
        $insert->bindValue(':type', $quest['type'], SQLITE3_TEXT);
        $insert->bindValue(':points', $quest['points'] ?? 1, SQLITE3_INTEGER);
        $insert->bindValue(':schedule', $quest['schedule'] ?? null, SQLITE3_TEXT);
        $insert->bindValue(':reminder', $quest['reminder_time'] ?? null, SQLITE3_TEXT);
        $insert->bindValue(':days_visible', $daysVisible, SQLITE3_TEXT);
        $insert->bindValue(':specific_dates', $specificDates, SQLITE3_TEXT);
        $insert->bindValue(':group_name', $quest['group_name'] ?? null, SQLITE3_TEXT);
        $insert->bindValue(':group_step', $quest['group_step'] ?? null, SQLITE3_INTEGER);
        $insert->execute();
    }

    $db->exec("COMMIT");
    $db->close();
    echo json_encode(['ok' => true, 'saved' => count($quests)]);
    exit;
}

// ============ ICAL PROXY ============

// GET proxy for iCal feeds (bypass CORS)
if ($method === 'GET' && $action === 'ical_proxy') {
    $url = $_GET['url'] ?? '';
    if (empty($url)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing URL']);
        exit;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid URL']);
        exit;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Quest-Tracker/1.0');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch calendar']);
        exit;
    }

    header('Content-Type: text/calendar');
    echo $response;
    exit;
}

// GET calendar quests (from quests table, type = 'calendar')
if ($method === 'GET' && $action === 'calendar_quests') {
    $db = get_db();
    $userId = get_user_id();
    $stmt = $db->prepare("SELECT * FROM quests WHERE user_id = :user AND type = 'calendar'");
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $quests = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['days_visible']) $row['days_visible'] = json_decode($row['days_visible'], true);
        if ($row['specific_dates']) $row['specific_dates'] = json_decode($row['specific_dates'], true);
        $quests[] = $row;
    }
    $db->close();
    echo json_encode(['quests' => $quests]);
    exit;
}

// PUT calendar quests – UPSERT only (never delete)
if ($method === 'PUT' && $action === 'calendar_quests') {
    $data = json_decode(file_get_contents('php://input'), true);
    $quests = $data['quests'] ?? [];
    $userId = get_user_id();
    $db = get_db();
    $db->exec("BEGIN TRANSACTION");

    $stmt = $db->prepare("INSERT OR REPLACE INTO quests (id, user_id, name, type, points, reminder_time, expires, created_at)
        VALUES (:id, :user, :name, 'calendar', :points, :reminder, :expires, :created)");

    foreach ($quests as $quest) {
        $stmt->bindValue(':id', $quest['id'], SQLITE3_TEXT);
        $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
        $stmt->bindValue(':name', $quest['name'], SQLITE3_TEXT);
        $stmt->bindValue(':points', $quest['points'] ?? 1, SQLITE3_INTEGER);
        $stmt->bindValue(':reminder', $quest['reminder_time'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':expires', $quest['expires'], SQLITE3_TEXT);
        $stmt->bindValue(':created', $quest['created_at'] ?? date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->execute();
    }

    $db->exec("COMMIT");
    $db->close();
    echo json_encode(['ok' => true, 'saved' => count($quests)]);
    exit;
}

// POST delete a single calendar quest
if ($method === 'POST' && $action === 'delete_calendar_quest') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? '';
    $db = get_db();
    $userId = get_user_id();
    $stmt = $db->prepare("DELETE FROM quests WHERE id = :id AND user_id = :user AND type = 'calendar'");
    $stmt->bindValue(':id', $id, SQLITE3_TEXT);
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
    echo json_encode(['ok' => true]);
    exit;
}

// POST delete all calendar quests
if ($method === 'POST' && $action === 'delete_all_calendar_quests') {
    $db = get_db();
    $userId = get_user_id();
    $stmt = $db->prepare("DELETE FROM quests WHERE user_id = :user AND type = 'calendar'");
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
    echo json_encode(['ok' => true]);
    exit;
}

// ============ THEME MANAGEMENT ============

// GET current theme
if ($method === 'GET' && $action === 'get_theme') {
    $file = DATA_DIR . '/themes.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        echo json_encode(['activeTheme' => $data['activeTheme'] ?? 'default']);
    } else {
        echo json_encode(['activeTheme' => 'default']);
    }
    exit;
}

// POST set active theme
if ($method === 'POST' && $action === 'set_theme') {
    $input = json_decode(file_get_contents('php://input'), true);
    $themeId = $input['theme'] ?? 'default';
    $file = DATA_DIR . '/themes.json';
    $themes = file_exists($file) ? json_decode(file_get_contents($file), true) : ['themes' => [], 'activeTheme' => 'default'];
    $themes['activeTheme'] = $themeId;
    file_put_contents($file, json_encode($themes, JSON_PRETTY_PRINT));
    echo json_encode(['ok' => true]);
    exit;
}

// POST save custom theme (from color picker)
if ($method === 'POST' && $action === 'save_custom_theme') {
    $input = json_decode(file_get_contents('php://input'), true);
    $colors = $input['colors'] ?? [];
    $css = ":root {\n";
    foreach ($colors as $var => $val) {
        $css .= "    $var: $val;\n";
    }
    $css .= "}\n";
    $cssDir = __DIR__ . '/css';
    if (!is_dir($cssDir)) mkdir($cssDir, 0755, true);
    file_put_contents($cssDir . '/custom-theme.css', $css);

    $file = DATA_DIR . '/themes.json';
    $themes = file_exists($file) ? json_decode(file_get_contents($file), true) : ['themes' => [], 'activeTheme' => 'default'];
    $themes['activeTheme'] = 'custom';
    $themes['customTheme'] = $colors;
    file_put_contents($file, json_encode($themes, JSON_PRETTY_PRINT));
    echo json_encode(['ok' => true]);
    exit;
}

// POST save a named theme preset
if ($method === 'POST' && $action === 'save_theme_preset') {
    $input = json_decode(file_get_contents('php://input'), true);
    $presetName = $input['name'] ?? 'My Theme';
    $colors = $input['colors'] ?? [];

    $id = 'preset_' . time();
    $fileName = 'theme-' . $id . '.css';
    $filePath = __DIR__ . '/css/' . $fileName;

    $css = ":root {\n";
    foreach ($colors as $var => $val) {
        $css .= "    $var: $val;\n";
    }
    $css .= "}\n";

    $cssDir = __DIR__ . '/css';
    if (!is_dir($cssDir)) mkdir($cssDir, 0755, true);
    file_put_contents($filePath, $css);

    $themesFile = DATA_DIR . '/themes.json';
    $themes = file_exists($themesFile) ? json_decode(file_get_contents($themesFile), true) : ['themes' => [], 'activeTheme' => 'default'];
    $themes['themes'][] = [
        'id' => $id,
        'name' => $presetName,
        'file' => 'css/' . $fileName,
        'isDefault' => false
    ];
    file_put_contents($themesFile, json_encode($themes, JSON_PRETTY_PRINT));

    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

// ============ STATS ACCESS ============

// GET stats access code (for external stats page)
if ($method === 'GET' && $action === 'stats_access_code') {
    header('Content-Type: application/json');
    echo json_encode(['code' => STATS_ACCESS_CODE]);
    exit;
}

// ============ DAILY RESET TRACKING ============

// GET last reset date
if ($method === 'GET' && $action === 'last_reset') {
    $db = get_db();
    $userId = get_user_id();
    $stmt = $db->prepare("SELECT last_reset FROM users WHERE id = :id");
    $stmt->bindValue(':id', $userId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $db->close();
    echo json_encode(['lastReset' => $row['last_reset'] ?? null]);
    exit;
}

// POST update last reset date
if ($method === 'POST' && $action === 'update_last_reset') {
    $data = json_decode(file_get_contents('php://input'), true);
    $db = get_db();
    $userId = get_user_id();
    $lastReset = $data['lastReset'] ?? null;
    $stmt = $db->prepare("UPDATE users SET last_reset = :lastReset WHERE id = :id");
    $stmt->bindValue(':lastReset', $lastReset, SQLITE3_TEXT);
    $stmt->bindValue(':id', $userId, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
    echo json_encode(['ok' => true]);
    exit;
}

// ============ FALLBACK ============
http_response_code(404);
echo json_encode(['error' => 'No matching endpoint', 'action' => $action, 'method' => $method]);