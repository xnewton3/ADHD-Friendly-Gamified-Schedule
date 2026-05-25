<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

define('DATA_DIR', __DIR__ . '/data');
define('DB_FILE', DATA_DIR . '/data.db');  // ← Changed to data.db
define('REGISTER_SECRET', 'your-secret-key-here'); // CHANGE THIS!

// Create data directory if it doesn't exist
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// Initialize database and tables
function init_database() {
    $db = new SQLite3(DB_FILE);

    // Users table
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id TEXT PRIMARY KEY,
        username TEXT NOT NULL,
        avatar TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // User settings
    $db->exec("CREATE TABLE IF NOT EXISTS user_settings (
        user_id TEXT PRIMARY KEY,
        small_reward_points INTEGER DEFAULT 10,
        big_reward_points INTEGER DEFAULT 25,
        theme TEXT DEFAULT 'dark',
        notifications_enabled INTEGER DEFAULT 0,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // Quests table
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

    // Quest completions
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

    // History/points log
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

    // Shop items
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

    // Achievements
    $db->exec("CREATE TABLE IF NOT EXISTS achievements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT NOT NULL,
        requirement_type TEXT NOT NULL,
        requirement_value INTEGER NOT NULL,
        points_reward INTEGER DEFAULT 0,
        icon TEXT
    )");

    // User achievements (earned)
    $db->exec("CREATE TABLE IF NOT EXISTS user_achievements (
        user_id TEXT NOT NULL,
        achievement_id INTEGER NOT NULL,
        earned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, achievement_id),
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (achievement_id) REFERENCES achievements(id)
    )");

    // Calendar feeds
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

    $db->close();
}

// Run initialization
init_database();

// Helper functions
function get_user_id() {
    return 'single_user';
}

function get_db() {
    return new SQLite3(DB_FILE);
}

$action = $_GET['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

// ============ TEST ENDPOINT ============
if ($method === 'GET' && $action === 'test') {
    echo json_encode(['ok' => true, 'php_version' => phpversion(), 'db' => DB_FILE]);
    exit;
}

// ============ USER ENDPOINTS ============

// GET user data
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

        // Get the new user
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
        // Parse JSON fields
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

// GET points (calculated from history)
if ($method === 'GET' && $action === 'points') {
    $db = get_db();
    $userId = get_user_id();

    // Convert string points to integers for sum
    $result = $db->querySingle("SELECT SUM(CAST(points AS INTEGER)) as total FROM history WHERE user_id = '$userId'", true);
    $totalPoints = $result['total'] ?? 0;

    $today = date('Y-m-d');
    $result = $db->querySingle("SELECT SUM(CAST(points AS INTEGER)) as today_total FROM history WHERE user_id = '$userId' AND date = '$today' AND CAST(points AS INTEGER) > 0", true);
    $todayPoints = $result['today_total'] ?? 0;

    $db->close();

    echo json_encode(['totalPoints' => $totalPoints, 'todayPoints' => $todayPoints]);
    exit;
}

// ============ QUEST COMPLETIONS ============

// GET today's completions
if ($method === 'GET' && $action === 'completions') {
    $db = get_db();
    $userId = get_user_id();
    $today = date('Y-m-d');

    $stmt = $db->prepare("SELECT quest_id, completed, points_earned FROM quest_completions WHERE user_id = :user AND completion_date = :date");
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $stmt->bindValue(':date', $today, SQLITE3_TEXT);
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

// POST toggle quest completion
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

    // Add to history if completed (store as plain integer, NO plus sign)
    if ($completed) {
        $stmt = $db->prepare("INSERT INTO history (user_id, type, description, points, date) VALUES (:user, 'quest_complete', :desc, :points, :date)");
        $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
        $stmt->bindValue(':desc', "Completed quest: " . str_replace("'", "''", $questName), SQLITE3_TEXT);
        $stmt->bindValue(':points', $points, SQLITE3_INTEGER);  // Just the number, no +
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

    // Check achievements
    check_achievements($db, $userId);

    $db->close();
    echo json_encode(['ok' => true, 'completed' => (bool)$completed]);
    exit;
}

// POST add bonus points for missed quests
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

// POST redeem reward
if ($method === 'POST' && $action === 'redeem') {
    $data = json_decode(file_get_contents('php://input'), true);
    $db = get_db();
    $userId = get_user_id();
    $today = date('Y-m-d');

    $itemName = $data['name'];
    $cost = $data['cost'];

    // Check if user has enough points (convert string points to integers)
    $result = $db->querySingle("SELECT SUM(CAST(points AS INTEGER)) as total FROM history WHERE user_id = '$userId'", true);
    $balance = $result['total'] ?? 0;

    if ($balance >= $cost) {
        // Add to history (store as negative integer, NO minus sign in string)
        $stmt = $db->prepare("INSERT INTO history (user_id, type, description, points, date) VALUES (:user, 'reward_redeem', :desc, :points, :date)");
        $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
        $stmt->bindValue(':desc', "Redeemed $itemName", SQLITE3_TEXT);
        $stmt->bindValue(':points', -$cost, SQLITE3_INTEGER);  // Negative integer
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

// GET achievements
if ($method === 'GET' && $action === 'achievements') {
    $db = get_db();
    $userId = get_user_id();

    // Get all achievements
    $achievementsResult = $db->query("SELECT * FROM achievements ORDER BY requirement_value");
    $allAchievements = [];
    while ($row = $achievementsResult->fetchArray(SQLITE3_ASSOC)) {
        $allAchievements[] = $row;
    }

    // Get earned ones
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
    // Get total quest count
    $questCount = $db->querySingle("SELECT COUNT(*) FROM history WHERE user_id = '$userId' AND type = 'quest_complete'");

    // Get streak (consecutive days with at least one completion)
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

    // Get lemonade completions (quests containing 'lemonade' or 'water')
    $lemonadeCount = $db->querySingle("SELECT COUNT(*) FROM history WHERE user_id = '$userId' AND type = 'quest_complete' AND (description LIKE '%lemonade%' OR description LIKE '%water%')");

    // Get morning quests (before 9 AM)
    $morningCount = $db->querySingle("SELECT COUNT(*) FROM history WHERE user_id = '$userId' AND type = 'quest_complete' AND time(timestamp) < '09:00:00'");

    // Get night quests (after 10 PM)
    $nightCount = $db->querySingle("SELECT COUNT(*) FROM history WHERE user_id = '$userId' AND type = 'quest_complete' AND time(timestamp) > '22:00:00'");

    // Check each achievement
    $achievementsResult = $db->query("SELECT * FROM achievements");
    while ($ach = $achievementsResult->fetchArray(SQLITE3_ASSOC)) {
        // Check if already earned
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
            // Award achievement
            $stmt = $db->prepare("INSERT INTO user_achievements (user_id, achievement_id) VALUES (:user, :ach)");
            $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
            $stmt->bindValue(':ach', $ach['id'], SQLITE3_INTEGER);
            $stmt->execute();

            // Award points
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

// GET avatar
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

// POST avatar
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

// GET history
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

// GET history stats (for dashboard)
if ($method === 'GET' && $action === 'history_stats') {
    $db = get_db();
    $userId = get_user_id();

    $stats = [];

    // Total points earned (sum of positive points) - convert to integer
    $result = $db->querySingle("SELECT SUM(CAST(points AS INTEGER)) as total FROM history WHERE user_id = '$userId' AND CAST(points AS INTEGER) > 0", true);
    $stats['total_points_earned'] = $result['total'] ?? 0;

    // Total points spent (sum of negative points)
    $result = $db->querySingle("SELECT SUM(ABS(CAST(points AS INTEGER))) as total FROM history WHERE user_id = '$userId' AND CAST(points AS INTEGER) < 0", true);
    $stats['total_points_spent'] = $result['total'] ?? 0;

    // Net points
    $stats['net_points'] = $stats['total_points_earned'] - $stats['total_points_spent'];

    // Total rewards redeemed
    $result = $db->querySingle("SELECT COUNT(*) as count FROM history WHERE user_id = '$userId' AND type = 'reward_redeem'");
    $stats['total_rewards'] = $result['count'] ?? 0;

    // Total quests completed
    $result = $db->querySingle("SELECT COUNT(*) as count FROM history WHERE user_id = '$userId' AND type = 'quest_complete'");
    $stats['total_quests'] = $result['count'] ?? 0;

    // Best day (by points earned)
    $result = $db->querySingle("SELECT date, SUM(CAST(points AS INTEGER)) as total FROM history WHERE user_id = '$userId' AND CAST(points AS INTEGER) > 0 GROUP BY date ORDER BY total DESC LIMIT 1", true);
    $stats['best_day'] = $result;

    $db->close();

    echo json_encode($stats);
    exit;
}

// ============ REMINDERS ============

// GET pending reminders
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

    // Get quests with reminders
    $stmt = $db->prepare("SELECT * FROM quests WHERE user_id = :user AND reminder_time IS NOT NULL AND reminder_time != ''");
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $result = $stmt->execute();

    $pendingReminders = [];
    while ($quest = $result->fetchArray(SQLITE3_ASSOC)) {
        // Check if already completed today
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

// ============ PASSKEY ============

// GET passkey status
if ($method === 'GET' && $action === 'passkey_status') {
    echo json_encode(['registered' => file_exists(DATA_DIR . '/credential.json')]);
    exit;
}

// POST update all non-calendar quests (from manager)
if ($method === 'POST' && $action === 'update_quests') {
    $data = json_decode(file_get_contents('php://input'), true);
    $quests = $data['quests'] ?? [];
    $userId = get_user_id();

    $db = get_db();
    $db->exec("BEGIN TRANSACTION");

    // Delete all existing non-calendar quests for this user
    $stmt = $db->prepare("DELETE FROM quests WHERE user_id = :user AND from_calendar = 0");
    $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $stmt->execute();

    // Insert updated quests
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

// Proxy for iCal feeds (bypass CORS)
if ($method === 'GET' && $action === 'ical_proxy') {
    $url = $_GET['url'] ?? '';
    if (empty($url)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing URL']);
        exit;
    }

    // Validate URL (only allow http/https)
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid URL']);
        exit;
    }

    // Fetch the iCal file
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
        // Parse JSON fields if any
        if ($row['days_visible']) $row['days_visible'] = json_decode($row['days_visible'], true);
        if ($row['specific_dates']) $row['specific_dates'] = json_decode($row['specific_dates'], true);
        $quests[] = $row;
    }
    $db->close();
    echo json_encode(['quests' => $quests]);
    exit;
}

// PUT calendar quests – UPSERT (update existing, insert new, delete missing)
if ($method === 'PUT' && $action === 'calendar_quests') {
    $data = json_decode(file_get_contents('php://input'), true);
    $quests = $data['quests'] ?? [];
    $userId = get_user_id();
    $db = get_db();
    $db->exec("BEGIN TRANSACTION");

    // Fetch existing calendar quest IDs for this user
    $existingStmt = $db->prepare("SELECT id FROM quests WHERE user_id = :user AND type = 'calendar'");
    $existingStmt->bindValue(':user', $userId, SQLITE3_TEXT);
    $existingResult = $existingStmt->execute();
    $existingIds = [];
    while ($row = $existingResult->fetchArray(SQLITE3_ASSOC)) {
        $existingIds[$row['id']] = true;
    }

    $insert = $db->prepare("INSERT OR REPLACE INTO quests (id, user_id, name, type, points, reminder_time, expires, created_at)
        VALUES (:id, :user, :name, 'calendar', :points, :reminder, :expires, :created)");

    $newIds = [];
    foreach ($quests as $quest) {
        $insert->bindValue(':id', $quest['id'], SQLITE3_TEXT);
        $insert->bindValue(':user', $userId, SQLITE3_TEXT);
        $insert->bindValue(':name', $quest['name'], SQLITE3_TEXT);
        $insert->bindValue(':points', $quest['points'] ?? 1, SQLITE3_INTEGER);
        $insert->bindValue(':reminder', $quest['reminder_time'] ?? null, SQLITE3_TEXT);
        $insert->bindValue(':expires', $quest['expires'], SQLITE3_TEXT);
        $insert->bindValue(':created', $quest['created_at'] ?? date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $insert->execute();
        $newIds[$quest['id']] = true;
    }

    // Delete calendar quests that are no longer present in the feed
    foreach ($existingIds as $id => $_) {
        if (!isset($newIds[$id])) {
            $delete = $db->prepare("DELETE FROM quests WHERE id = :id AND user_id = :user");
            $delete->bindValue(':id', $id, SQLITE3_TEXT);
            $delete->bindValue(':user', $userId, SQLITE3_TEXT);
            $delete->execute();
            // Also remove orphaned completions
            $delComp = $db->prepare("DELETE FROM quest_completions WHERE quest_id = :id AND user_id = :user");
            $delComp->bindValue(':id', $id, SQLITE3_TEXT);
            $delComp->bindValue(':user', $userId, SQLITE3_TEXT);
            $delComp->execute();
        }
    }

    $db->exec("COMMIT");
    $db->close();
    echo json_encode(['ok' => true, 'saved' => count($quests)]);
    exit;
}

// ============ FALLBACK ============

// If we get here, return error
http_response_code(404);
echo json_encode(['error' => 'No matching endpoint', 'action' => $action, 'method' => $method]);