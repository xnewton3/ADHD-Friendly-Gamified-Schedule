<?php
ob_start();

register_shutdown_function(function () {
    $buffer = ob_get_clean();
    if ($buffer === false || $buffer === '') return;

    // Only sanity-check JSON responses — leave PNG/iCal/etc. alone
    $isJson = false;
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type: application/json') === 0) {
            $isJson = true;
            break;
        }
    }
    if (!$isJson) {
        echo $buffer;
        return;
    }

    json_decode($buffer);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo $buffer;
        return;
    }

    error_log('Corrupt API output: ' . $buffer);
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
    }
    echo json_encode(['error' => 'Internal server error — check PHP error log']);
});

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

// Replace the display_errors block in api.php with:
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, X-Stats-Access-Code, X-API-Key');

// CORS preflight (browser-based Home Assistant cards send this before the real request)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('DATA_DIR', __DIR__ . '/data');
define('DB_FILE', DATA_DIR . '/data.db');

// Share of main quests that must be done before side quests unlock (the "system default"; adjustable in Manager)
define('SIDE_UNLOCK_DEFAULT', 25);

// Create data directory if it doesn't exist
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// Block direct web access to the data folder (database + app_settings.json with the API key).
// Apache 2.4 reads this file; verify by opening /data/data.db in a browser -> must be 403.
if (is_dir(DATA_DIR) && !file_exists(DATA_DIR . '/.htaccess')) {
    @file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n");
}

// Initialize database and tables
function init_database() {
    $db = new SQLite3(DB_FILE);
    $db->enableExceptions(true);
    $db->busyTimeout(5000); // wait up to 5s instead of instantly throwing SQLITE_BUSY

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

    $db->exec("CREATE TABLE IF NOT EXISTS day_presets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        name TEXT NOT NULL,
        days TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS quest_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        trigger_quest_id TEXT NOT NULL,
        linked_quest_id TEXT NOT NULL,
        wait_minutes INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(trigger_quest_id, linked_quest_id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS daily_reminder_overrides (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        quest_id TEXT NOT NULL,
        date TEXT NOT NULL,
        reminder_time TEXT NOT NULL,
        UNIQUE(user_id, quest_id, date)
    )");

    // Add new quest columns to existing installs (safe to re-run)
    foreach (['priority INTEGER DEFAULT 0', 'ha_completable INTEGER DEFAULT 0'] as $col) {
        try { $db->exec("ALTER TABLE quests ADD COLUMN $col"); } catch (Exception $e) {}
    }

    // Seed achievements by name – safe to add new ones to existing installs
    $allAchievements = [
        // ── Quest count ──
        ['First Quest',        'Complete your first quest',                    'quest_count',      1,   5,  '🎯'],
        ['Quest Novice',       'Complete 10 quests',                           'quest_count',     10,  10,  '🌟'],
        ['Quest Master',       'Complete 100 quests',                          'quest_count',    100,  50,  '🏆'],
        ['Quest Legend',       'Complete 500 quests',                          'quest_count',    500,  50,  '👑'],
        ['Quest Veteran',      'Complete 1,000 quests',                        'quest_count',   1000,  50,  '🎖️'],
        ['Quest Immortal',     'Complete 5,000 quests',                        'quest_count',   5000,  50,  '🌌'],
        ['Quest God',          'Complete 10,000 quests',                       'quest_count',  10000,  50,  '🌠'],
        ['Quest Titan',        'Complete 25,000 quests',                       'quest_count',  25000,  50,  '⚡'],
        // ── Streaks ──
        ['7 Day Streak',       'Complete quests for 7 days in a row',          'streak',           7,  20,  '🔥'],
        ['30 Day Streak',      'Complete quests for 30 days in a row',         'streak',          30,  50,  '⚡'],
        ['100 Day Streak',     'Complete quests for 100 days in a row',        'streak',         100,  50,  '🏅'],
        ['6 Month Streak',     'Complete quests for 180 days in a row',        'streak',         180,  50,  '🔩'],
        ['365 Day Streak',     'Complete quests for a full year straight',     'streak',         365,  50,  '💪'],
        // ── Hydration ──
        ['Hydration Hero',     'Complete water quest for 7 days total',        'lemonade_days',    7,  15,  '💧'],
        ['Hydration Master',   'Complete water quest for 30 days total',       'lemonade_days',   30,  40,  '🥤'],
        ['Hydration Veteran',  'Complete water quest for 100 days total',      'lemonade_days',  100,  50,  '💦'],
        ['Hydration Legend',   'Complete water quest for 365 days total',      'lemonade_days',  365,  50,  '🌊'],
        ['Hydration Oracle',   'Complete water quest for 730 days total',      'lemonade_days',  730,  50,  '🫧'],
        // ── Time of day ──
        ['Early Bird',         'Complete 10 quests before 9 AM',              'morning_quests',  10,  25,  '🌅'],
        ['Dawn Chaser',        'Complete 100 quests before 9 AM',             'morning_quests', 100,  50,  '🌄'],
        ['Dawn Master',        'Complete 500 quests before 9 AM',             'morning_quests', 500,  50,  '☀️'],
        ['Night Owl',          'Complete 10 quests after 10 PM',              'night_quests',    10,  25,  '🌙'],
        ['Midnight Grinder',   'Complete 100 quests after 10 PM',             'night_quests',   100,  50,  '🌃'],
        ['Insomniac',          'Complete 365 quests after 10 PM',             'night_quests',   365,  50,  '🦉'],
        ['Night Legend',       'Complete 500 quests after 10 PM',             'night_quests',   500,  50,  '🌌'],
        ['Night God',          'Complete 1,000 quests after 10 PM',           'night_quests',  1000,  50,  '🌑'],
        // ── Total points earned (lifetime) ──
        ['First Points',       'Earn your first 100 points',                  'total_points',   100,  10,  '💰'],
        ['Point Hoarder',      'Accumulate 500 points earned',                'total_points',   500,  25,  '💎'],
        ['Treasury',           'Accumulate 1,000 points earned',              'total_points',  1000,  50,  '🏦'],
        ['Grand Vault',        'Accumulate 5,000 points earned',              'total_points',  5000,  50,  '🏰'],
        ['Point Millionaire',  'Accumulate 10,000 points earned',             'total_points', 10000,  50,  '💵'],
        ['Eternal Vault',      'Accumulate 25,000 points earned',             'total_points', 25000,  50,  '💫'],
        ['Legend Vault',       'Accumulate 50,000 points earned',             'total_points', 50000,  50,  '🌟'],
        // ── Active days (non-consecutive distinct days) ──
        ['Week Warrior',       'Complete quests on 7 different days',         'active_days',      7,  10,  '⚔️'],
        ['Month Champion',     'Complete quests on 30 different days',        'active_days',     30,  30,  '🛡️'],
        ['Centurion',          'Complete quests on 100 different days',       'active_days',    100,  50,  '🗡️'],
        ['Yearlong Devotion',  'Complete quests on 365 different days',       'active_days',    365,  50,  '🌍'],
        ['Active Marathon',    'Complete quests on 500 different days',       'active_days',    500,  50,  '🏃'],
        ['Active Legend',      'Complete quests on 730 different days',       'active_days',    730,  50,  '♾️'],
        // ── Rewards redeemed ──
        ['Treat Yourself',     'Redeem a reward for the first time',          'reward_redeems',   1,   5,  '🎁'],
        ['Shopaholic',         'Redeem 10 rewards',                           'reward_redeems',  10,  30,  '🛍️'],
        ['Reward Royalty',     'Redeem 50 rewards',                           'reward_redeems',  50,  50,  '🎀'],
        ['Shopkeeper',         'Redeem 100 rewards',                          'reward_redeems', 100,  50,  '🏪'],
        ['Legendary Shopper',  'Redeem 200 rewards',                          'reward_redeems', 200,  50,  '🎰'],
    ];
    // Hard cap: no achievement ever awards more than 50pts (also fixes any existing rows)
    $db->exec("UPDATE achievements SET points_reward = 50 WHERE points_reward > 50");
    $stmt = $db->prepare("INSERT INTO achievements (name, description, requirement_type, requirement_value, points_reward, icon) VALUES (:name, :description, :req_type, :req_value, :points, :icon)");
    foreach ($allAchievements as $ach) {
        $escapedName = SQLite3::escapeString($ach[0]);
        $exists = $db->querySingle("SELECT COUNT(*) FROM achievements WHERE name = '$escapedName'");
        if (!$exists) {
            $stmt->bindValue(':name',        $ach[0], SQLITE3_TEXT);
            $stmt->bindValue(':description', $ach[1], SQLITE3_TEXT);
            $stmt->bindValue(':req_type',    $ach[2], SQLITE3_TEXT);
            $stmt->bindValue(':req_value',   $ach[3], SQLITE3_INTEGER);
            $stmt->bindValue(':points',      $ach[4], SQLITE3_INTEGER);
            $stmt->bindValue(':icon',        $ach[5], SQLITE3_TEXT);
            $stmt->execute();
            // Silently mark as earned if user already meets criteria — prevents achievement blast
            $newId   = $db->lastInsertRowID();
            $uid     = 'single_user';
            $reqType = $ach[2];
            $reqVal  = (int)$ach[3];
            $met = false;
            switch ($reqType) {
                case 'quest_count':
                    $met = (int)$db->querySingle("SELECT COUNT(*) FROM history WHERE user_id='$uid' AND type='quest_complete'") >= $reqVal; break;
                case 'total_points':
                    $met = (int)$db->querySingle("SELECT COALESCE(SUM(points),0) FROM history WHERE user_id='$uid' AND points>0") >= $reqVal; break;
                case 'active_days':
                    $met = (int)$db->querySingle("SELECT COUNT(DISTINCT date) FROM history WHERE user_id='$uid' AND type='quest_complete'") >= $reqVal; break;
                case 'reward_redeems':
                    $met = (int)$db->querySingle("SELECT COUNT(*) FROM history WHERE user_id='$uid' AND type='reward_redeem'") >= $reqVal; break;
                case 'lemonade_days':
                    $met = (int)$db->querySingle("SELECT COUNT(DISTINCT date) FROM history WHERE user_id='$uid' AND type='quest_complete' AND (description LIKE '%water%' OR description LIKE '%lemonade%')") >= $reqVal; break;
                case 'morning_quests':
                    $met = (int)$db->querySingle("SELECT COUNT(*) FROM history WHERE user_id='$uid' AND type='quest_complete' AND time(timestamp)<'09:00:00'") >= $reqVal; break;
                case 'night_quests':
                    $met = (int)$db->querySingle("SELECT COUNT(*) FROM history WHERE user_id='$uid' AND type='quest_complete' AND time(timestamp)>'22:00:00'") >= $reqVal; break;
                // streak: skipped — complex calculation; new thresholds are unlikely already met
            }
            if ($met) {
                $db->exec("INSERT OR IGNORE INTO user_achievements (user_id, achievement_id, earned_at) VALUES ('$uid', $newId, CURRENT_TIMESTAMP)");
            }
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
try {
    init_database();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database init failed: ' . $e->getMessage()]);
    exit;
}

// Start session for password login persistence
try {
    session_start();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Session start failed: ' . $e->getMessage()]);
    exit;
}

// Helper function to check if user is logged in via password
// The "stay logged in" cookie is a signed token (expiry + HMAC), so it can't be forged by
// hand-editing a cookie. The signing key is derived from the password: changing the password
// logs every device out.
function authCookieSecret() {
    return hash('sha256', 'quest-tracker-auth|' . PASSKEY_CREATION_KEY);
}

function makeAuthToken() {
    $expires = time() + (21 * 24 * 3600);
    return $expires . '.' . hash_hmac('sha256', (string)$expires, authCookieSecret());
}

function validAuthToken($token) {
    if (!is_string($token) || strpos($token, '.') === false) return false;
    $parts   = explode('.', $token, 2);
    $expires = $parts[0];
    $sig     = $parts[1];
    if (!ctype_digit($expires) || (int)$expires < time()) return false;
    return hash_equals(hash_hmac('sha256', $expires, authCookieSecret()), $sig);
}

function isLoggedIn() {
    if (isset($_SESSION['password_verified']) && $_SESSION['password_verified'] === true) {
        return true;
    }
    if (isset($_COOKIE['password_verified']) && validAuthToken($_COOKIE['password_verified'])) {
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
    $db = new SQLite3(DB_FILE);
    $db->busyTimeout(5000);
    return $db;
}

// Helper to check if a quest is visible on a given date based on its schedule.
// "Today" according to the app: uses the saved timezone, and the day rolls over at 3:00 AM
function appToday() {
    static $tzName = null;
    if ($tzName === null) {
        $f = DATA_DIR . '/app_settings.json';
        $s = file_exists($f) ? json_decode(file_get_contents($f), true) : [];
        $tzName = $s['timezone'] ?? 'Europe/Amsterdam';
        if (!in_array($tzName, DateTimeZone::listIdentifiers())) $tzName = 'Europe/Amsterdam';
    }
    $now = new DateTime('now', new DateTimeZone($tzName));
    if ((int)$now->format('G') < 3) $now->modify('-1 day');
    return $now->format('Y-m-d');
}

// Apply per-day reminder_time overrides (set when a linked quest's trigger is completed)
function applyReminderOverrides(array $quests, $db, $userId, $date) {
    if (empty($quests)) return $quests;
    $uid  = SQLite3::escapeString($userId);
    $d    = SQLite3::escapeString($date);
    $ids  = array_map(function($q) { return SQLite3::escapeString($q['id']); }, $quests);
    $in   = "'" . implode("','", $ids) . "'";
    $res  = $db->query("SELECT quest_id, reminder_time FROM daily_reminder_overrides WHERE user_id='$uid' AND date='$d' AND quest_id IN ($in)");
    $map  = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $map[$r['quest_id']] = $r['reminder_time'];
    foreach ($quests as &$q) {
        if (isset($map[$q['id']])) $q['reminder_time'] = $map[$q['id']];
    }
    unset($q);
    return $quests;
}

function isQuestVisibleOnDate($quest, $date, $db, $userId) {
    $schedule = $quest['schedule'] ?? '';
    $questId  = $quest['id'];

    // ── Weekly modifier: 'Weekly:X' = show per schedule X, once per Mon–Sun week ──
    $isWeekly = false;
    if (strpos($schedule, 'Weekly:') === 0) {
        $isWeekly = true;
        $schedule = substr($schedule, 7); // strip the 'Weekly:' prefix
    } elseif ($schedule === 'Weekly') {
        $isWeekly = true;
        $schedule = 'Daily'; // bare 'Weekly' (old format) = every day, once a week
    }

    // ── Calendar quests: date-specific, weekly modifier ignored ──
    if (($quest['type'] ?? '') === 'calendar') {
        $expires = $quest['expires'] ?? null;
        if (!$expires) return false;
        return substr($expires, 0, 10) === $date;
    }

    // ── One-time: lifetime scope, weekly modifier ignored ──
    if ($schedule === 'One-time') {
        $stmt = $db->prepare("SELECT completed FROM quest_completions WHERE quest_id = :qid AND user_id = :uid AND completed = 1 LIMIT 1");
        $stmt->bindValue(':qid', $questId, SQLITE3_TEXT);
        $stmt->bindValue(':uid', $userId, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $row === false;
    }

    // ── Resolve base schedule visibility ──
    $visible = false;

    if ($schedule === 'Daily') {
        $visible = true;
    } elseif ($schedule === 'Weekend') {
        $visible = in_array(date('l', strtotime($date)), ['Saturday', 'Sunday']);
    } elseif (preg_match('/^(odd|even|1st|2nd|3rd|4th) Daily$/', $schedule, $pm)) {
        // Patterned daily: e.g. "odd Daily" = every day on odd ISO weeks
        $wn = (int)date('W', strtotime($date));
        switch ($pm[1]) {
            case 'odd':  $visible = ($wn % 2 === 1); break;
            case 'even': $visible = ($wn % 2 === 0); break;
            case '1st':  $visible = (($wn - 1) % 4 === 0); break;
            case '2nd':  $visible = (($wn - 2) % 4 === 0); break;
            case '3rd':  $visible = (($wn - 3) % 4 === 0); break;
            case '4th':  $visible = (($wn - 4) % 4 === 0); break;
            default:     $visible = false;
        }
    } elseif ($schedule === 'DD') {
        $visible = in_array(date('l', strtotime($date)), ['Thursday', 'Friday', 'Saturday', 'Sunday']);
    } elseif (strpos($schedule, 'days:') !== false) {
        // Select-Days: "days:Monday,Friday" or "odd days:Monday,Friday"
        if (preg_match('/^(.*?)days:(.+)$/', $schedule, $m)) {
            $dayPat  = trim($m[1]) ?: 'every';
            $dayList = array_map('trim', explode(',', $m[2]));
            $today   = date('l', strtotime($date));
            if (!in_array($today, $dayList)) {
                $visible = false;
            } elseif ($dayPat === 'every') {
                $visible = true;
            } else {
                $wn = (int)date('W', strtotime($date));
                switch ($dayPat) {
                    case 'odd':  $visible = ($wn % 2 === 1); break;
                    case 'even': $visible = ($wn % 2 === 0); break;
                    case '1st':  $visible = (($wn - 1) % 4 === 0); break;
                    case '2nd':  $visible = (($wn - 2) % 4 === 0); break;
                    case '3rd':  $visible = (($wn - 3) % 4 === 0); break;
                    case '4th':  $visible = (($wn - 4) % 4 === 0); break;
                    default:     $visible = false;
                }
            }
        } else {
            $visible = false;
        }
    } elseif (strpos($schedule, '/') !== false) {
        $visible = matchesDatePattern($schedule, new DateTime($date));
    } else {
        // Weekday [+ pattern], e.g. "Monday" or "odd Monday"
        $parts   = explode(' ', $schedule);
        $weekday = end($parts);
        $pattern = count($parts) === 1 ? 'every' : $parts[0];
        $validWeekdays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        if (!in_array($weekday, $validWeekdays)) return false;
        if (date('l', strtotime($date)) !== $weekday) return false;
        if ($pattern === 'every') {
            $visible = true;
        } else {
            $weekNum = (int)date('W', strtotime($date));
            switch ($pattern) {
                case 'odd':  $visible = ($weekNum % 2 === 1); break;
                case 'even': $visible = ($weekNum % 2 === 0); break;
                case '1st':  $visible = (($weekNum - 1) % 4 === 0); break;
                case '2nd':  $visible = (($weekNum - 2) % 4 === 0); break;
                case '3rd':  $visible = (($weekNum - 3) % 4 === 0); break;
                case '4th':  $visible = (($weekNum - 4) % 4 === 0); break;
                default:     return false;
            }
        }
    }

    if (!$visible) return false;
    if (!$isWeekly) return true;

    // ── Weekly check: hide if already completed this Mon–Sun week ──
    $dateObj    = new DateTime($date);
    $dow        = (int)$dateObj->format('N'); // 1 = Mon, 7 = Sun
    $monday     = (clone $dateObj)->modify('-' . ($dow - 1) . ' days')->format('Y-m-d');
    $sunday     = (clone $dateObj)->modify('+' . (7 - $dow) . ' days')->format('Y-m-d');
    $escapedQid = SQLite3::escapeString($questId);
    $escapedUid = SQLite3::escapeString($userId);
    $done = (int)$db->querySingle(
        "SELECT COUNT(*) FROM quest_completions
         WHERE quest_id = '$escapedQid' AND user_id = '$escapedUid'
           AND completed = 1 AND completion_date >= '$monday' AND completion_date <= '$sunday'"
    );
    return $done === 0;
}


// Helper to match a custom date pattern like "15/&&/&&&&"
function matchesDatePattern($pattern, $date) {
    if (!$pattern || $pattern === '&&/&&/&&&&') return true;
    $day = str_pad($date->format('d'), 2, '0', STR_PAD_LEFT);
    $month = str_pad($date->format('m'), 2, '0', STR_PAD_LEFT);
    $year = $date->format('Y');
    $parts = explode('/', $pattern);
    if (count($parts) !== 3) return false;
    if ($parts[0] !== '&&' && $parts[0] !== $day) return false;
    if ($parts[1] !== '&&' && $parts[1] !== $month) return false;
    if ($parts[2] !== '&&&&' && $parts[2] !== $year) return false;
    return true;
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
    $totalPoints = (int)$db->querySingle("SELECT COALESCE(SUM(points), 0) FROM history WHERE user_id = '$userId' AND points > 0");
    $activeDays = (int)$db->querySingle("SELECT COUNT(DISTINCT date) FROM history WHERE user_id = '$userId' AND type = 'quest_complete'");
    $rewardRedeems = (int)$db->querySingle("SELECT COUNT(*) FROM history WHERE user_id = '$userId' AND type = 'reward_redeem'");

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
            case 'total_points':
                if ($totalPoints >= $ach['requirement_value']) $earned = true;
                break;
            case 'active_days':
                if ($activeDays >= $ach['requirement_value']) $earned = true;
                break;
            case 'reward_redeems':
                if ($rewardRedeems >= $ach['requirement_value']) $earned = true;
                break;
        }

        if ($earned) {
            $stmt = $db->prepare("INSERT INTO user_achievements (user_id, achievement_id) VALUES (:user, :ach)");
            $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
            $stmt->bindValue(':ach', $ach['id'], SQLITE3_INTEGER);
            $stmt->execute();

            if ($ach['points_reward'] > 0) {
                $today = appToday();
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

// ── iCal helpers (server-side parsing) ───────────────────────────────────────

function parseICalDatePHP($dateStr) {
    if (strpos($dateStr, ':') !== false) $dateStr = substr($dateStr, strrpos($dateStr, ':') + 1);
    $dateStr = trim($dateStr);
    if (!preg_match('/^\d{8}(T\d{6}Z?)?$/', $dateStr)) return null;
    $year  = (int)substr($dateStr, 0, 4);
    $month = (int)substr($dateStr, 4, 2);
    $day   = (int)substr($dateStr, 6, 2);
    $hour = 0; $min = 0; $sec = 0;
    if (strlen($dateStr) > 8 && strpos($dateStr, 'T') !== false) {
        $t    = substr($dateStr, strpos($dateStr, 'T') + 1);
        $hour = (int)substr($t, 0, 2);
        $min  = (int)substr($t, 2, 2);
        $sec  = (int)substr($t, 4, 2);
    }
    return new DateTime(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $min, $sec));
}

// iCal event description marker. The word "ical" opts an event in; an optional ",N" (or ":N") gives its points.
// Everything after the marker is ignored.
//   ical,1.anything   -> 1 point        ical. anything     -> 1 point (default)
//   ical,5. anything  -> 5 points       no "ical" word     -> null (event is skipped)
function icalDescriptionPoints($desc) {
    if (!is_string($desc) || !preg_match('/\bical(?:\s*[,:]\s*(\d+))?/i', $desc, $m)) return null;
    return isset($m[1]) ? max(1, (int)$m[1]) : 1;
}

function parseICalPHP($icalData, $todayStr) {
    $events = [];
    $lines  = preg_split('/\r?\n/', $icalData);
    $count  = count($lines);
    $current = null;
    for ($i = 0; $i < $count; $i++) {
        $line = rtrim($lines[$i]);
        while ($i + 1 < $count && strlen($lines[$i + 1]) > 0 && ($lines[$i + 1][0] === ' ' || $lines[$i + 1][0] === "\t")) {
            $i++;
            $line .= ltrim($lines[$i]);
        }
        if ($line === 'BEGIN:VEVENT') {
            $current = [];
        } elseif ($line === 'END:VEVENT' && $current !== null && isset($current['summary'])) {
            if (isset($current['points'])) { // only events whose description has the ical marker
                $events[] = $current;
            }
            $current = null;
        } elseif ($current !== null && ($cp = strpos($line, ':')) !== false) {
            $key   = strtoupper(explode(';', substr($line, 0, $cp))[0]);
            $value = substr($line, $cp + 1);
            switch ($key) {
                case 'SUMMARY':
                    $current['summary'] = str_replace(['\\,','\\n','\\;'],[',',"\n",';'], $value); break;
                case 'DESCRIPTION':
                    $d = str_replace(['\\,','\\n','\\;'],[',',"\n",';'], $value);
                    $current['description'] = $d;
                    $current['points'] = icalDescriptionPoints($d); break;
                case 'DTSTART': $current['start'] = parseICalDatePHP($value); break;
                case 'DTEND':   $current['end']   = parseICalDatePHP($value); break;
                case 'RRULE':   $current['rrule'] = $value; break;
                case 'UID':     $current['uid']   = $value; break;
            }
        }
    }
    $today = new DateTime($todayStr . ' 00:00:00');
    $cap   = (clone $today)->modify('+30 days');
    $out   = [];
    foreach ($events as $ev) {
        if (empty($ev['start'])) continue;
        $rrule = $ev['rrule'] ?? '';
        if ($rrule && strpos($rrule, 'FREQ=WEEKLY') !== false) {
            $cur = clone $ev['start'];
            $dur = isset($ev['end']) ? $ev['start']->diff($ev['end']) : null;
            while ($cur < $today) $cur->modify('+7 days');
            while ($cur <= $cap) {
                $end = $dur ? (clone $cur)->add($dur) : clone $cur;
                $out[] = ['summary'=>$ev['summary'],'start'=>clone $cur,'end'=>$end,
                          'uid'=>($ev['uid']??'uid').'_'.$cur->format('Y-m-d'),
                          'points'=>$ev['points'],'description'=>$ev['description']??''];
                $cur->modify('+7 days');
            }
        } else {
            $end = $ev['end'] ?? $ev['start'];
            if ($end >= $today && $end <= $cap) {
                $out[] = ['summary'=>$ev['summary'],'start'=>$ev['start'],'end'=>$end,
                          'uid'=>$ev['uid']??'uid','points'=>$ev['points'],'description'=>$ev['description']??''];
            }
        }
    }
    usort($out, function($a, $b) { return $a['start'] <=> $b['start']; });
    return $out;
}

// Replicates the JS hashCode() used by index.html to generate stable calendar quest IDs
function calQuestHashCode($str) {
    $hash = 0;
    for ($i = 0; $i < strlen($str); $i++) {
        $hash = ((($hash << 5) - $hash) + ord($str[$i])) | 0;
        $hash &= 0xFFFFFFFF;
        if ($hash >= 0x80000000) $hash -= 0x100000000;
    }
    return base_convert(abs($hash), 10, 36);
}

$action = $_GET['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

// Public endpoints (no auth of any kind required)
$public_actions = ['test', 'login_status', 'verify_password', 'logout', 'stats_access_code', 'ical_proxy', 'get_theme'];

// Stats endpoints: can be accessed via stats code header (GET only) OR normal login
$stats_actions = ['', 'history', 'history_stats', 'points', 'today_bonus_points', 'completions', 'achievements', 'shop_items', 'calendar_feeds', 'calendar_quests', 'avatar', 'last_reset', 'visible_quests', 'get_timezone'];

// Webhook / Home Assistant endpoints: no session login, but every one of them checks the API key
$webhook_actions = ['ha_quests', 'ha_status', 'ha_shop', 'ha_complete', 'ha_add_quest', 'ha_redeem', 'ha_delete_quest'];

// Authentication logic
if (!in_array($action, $public_actions) && !in_array($action, $webhook_actions)) {
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

# Error Handling potential fix starts here:
try {

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

        if (is_string($password) && hash_equals((string)PASSKEY_CREATION_KEY, $password)) {
            $_SESSION['password_verified'] = true;
            // Set a cookie that lasts 3 weeks (21 days) – renewed on each page load
            setcookie('password_verified', makeAuthToken(), [
                'expires'  => time() + (21 * 24 * 3600),
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
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

        // Get all quests from DB
        $stmt = $db->prepare("SELECT * FROM quests WHERE user_id = :id ORDER BY created_at");
        $stmt->bindValue(':id', $userId, SQLITE3_TEXT);
        $questsResult = $stmt->execute();
        $allQuests = [];
        while ($row = $questsResult->fetchArray(SQLITE3_ASSOC)) {
            if ($row['days_visible']) $row['days_visible'] = json_decode($row['days_visible'], true);
            if ($row['specific_dates']) $row['specific_dates'] = json_decode($row['specific_dates'], true);
            $allQuests[] = $row;
        }

        // Filter visible quests for today
        $today = appToday();
        $quests = [];
        foreach ($allQuests as $quest) {
            if (isQuestVisibleOnDate($quest, $today, $db, $userId)) {
                $quests[] = $quest;
            }
        }

        $quests = applyReminderOverrides($quests, $db, $userId, $today);

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

        $today = appToday();
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
        $todayTimestamp = strtotime(appToday());
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

    // GET all quests (including completed one-time) for Manager
    if ($method === 'GET' && $action === 'all_quests') {
        $db = get_db();
        $userId = get_user_id();
        $stmt = $db->prepare("SELECT * FROM quests WHERE user_id = :id ORDER BY created_at");
        $stmt->bindValue(':id', $userId, SQLITE3_TEXT);
        $questsResult = $stmt->execute();
        $quests = [];
        while ($row = $questsResult->fetchArray(SQLITE3_ASSOC)) {
            if ($row['days_visible']) $row['days_visible'] = json_decode($row['days_visible'], true);
            if ($row['specific_dates']) $row['specific_dates'] = json_decode($row['specific_dates'], true);
            $quests[] = $row;
        }
        $db->close();
        echo json_encode(['quests' => $quests]);
        exit;
    }

    // GET completions for a specific date (default today)
    if ($method === 'GET' && $action === 'completions') {
        $db = get_db();
        $userId = get_user_id();
        $dateParam = isset($_GET['date']) ? $_GET['date'] : appToday();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
            $dateParam = appToday();
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
        $today = appToday();

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
            $stmt = $db->prepare("DELETE FROM history WHERE id = (SELECT id FROM history WHERE user_id = :user AND type = 'quest_complete' AND description = :desc AND date = :date ORDER BY id DESC LIMIT 1)");
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

        // ── Quest links: update daily reminder overrides for linked quests ────────
        $reminderUpdates = [];
        if ($completed) {
            $uid2 = SQLite3::escapeString($userId);
            $qid2 = SQLite3::escapeString($questId);
            $sFile = DATA_DIR . '/app_settings.json';
            $sData = file_exists($sFile) ? json_decode(file_get_contents($sFile), true) : [];
            $tz    = new DateTimeZone($sData['timezone'] ?? 'Europe/Amsterdam');
            $now   = new DateTime('now', $tz);
            $eod   = (new DateTime($today . ' 00:00:00', $tz))->modify('+1 day 2 hours 59 minutes'); // cap at 11:59 PM

            $linksRes = $db->query("SELECT id, linked_quest_id, wait_minutes FROM quest_links WHERE trigger_quest_id='$qid2' AND user_id='$uid2'");
            while ($lnk = $linksRes->fetchArray(SQLITE3_ASSOC)) {
                $lqid     = SQLite3::escapeString($lnk['linked_quest_id']);
                $newTime  = clone $now;
                $newTime->modify('+' . (int)$lnk['wait_minutes'] . ' minutes');
                if ($newTime > $eod) $newTime = $eod;
                $formatted = $newTime->format('g:i a'); // "10:34 am"
                $db->exec("INSERT OR REPLACE INTO daily_reminder_overrides (user_id, quest_id, date, reminder_time) VALUES ('$uid2', '$lqid', '$today', '$formatted')");
                $reminderUpdates[] = ['quest_id' => $lnk['linked_quest_id'], 'reminder_time' => $formatted];
            }
        }

        $db->close();
        echo json_encode(['ok' => true, 'completed' => (bool)$completed, 'reminder_updates' => $reminderUpdates]);
        exit;
    }

    // POST add bonus points for missed quests (bulk)
    if ($method === 'POST' && $action === 'add_bonus') {
        $data = json_decode(file_get_contents('php://input'), true);
        $db = get_db();
        $userId = get_user_id();
        $today = appToday();
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

    // GET quests that were due yesterday but not completed
    if ($method === 'GET' && $action === 'missed_quests') {
        $db = get_db();
        $userId = get_user_id();
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        // Get all quests
        $stmt = $db->prepare("SELECT * FROM quests WHERE user_id = :id");
        $stmt->bindValue(':id', $userId, SQLITE3_TEXT);
        $questsResult = $stmt->execute();
        $allQuests = [];
        while ($row = $questsResult->fetchArray(SQLITE3_ASSOC)) {
            if ($row['days_visible']) $row['days_visible'] = json_decode($row['days_visible'], true);
            if ($row['specific_dates']) $row['specific_dates'] = json_decode($row['specific_dates'], true);
            $allQuests[] = $row;
        }

        // Get completions for yesterday
        $stmt = $db->prepare("SELECT quest_id, completed FROM quest_completions WHERE user_id = :user AND completion_date = :date");
        $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
        $stmt->bindValue(':date', $yesterday, SQLITE3_TEXT);
        $completionsResult = $stmt->execute();
        $completions = [];
        while ($row = $completionsResult->fetchArray(SQLITE3_ASSOC)) {
            $completions[$row['quest_id']] = (bool)$row['completed'];
        }

        $missedQuests = [];
        foreach ($allQuests as $quest) {
            // Skip if completed yesterday or already completed today (we treat completed today as not missed)
            if (isset($completions[$quest['id']]) && $completions[$quest['id']]) continue;
            // Check if it was due yesterday using the same backend function
            if (isQuestVisibleOnDate($quest, $yesterday, $db, $userId)) {
                $missedQuests[] = $quest;
            }
        }

        $db->close();
        echo json_encode(['missed' => $missedQuests]);
        exit;
    }

    // GET quests visible on a given date
    if ($method === 'GET' && $action === 'visible_quests') {
        $date = $_GET['date'] ?? appToday();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid date format']);
            exit;
        }

        $db = get_db();
        $userId = get_user_id();

        $stmt = $db->prepare("SELECT * FROM quests WHERE user_id = :id");
        $stmt->bindValue(':id', $userId, SQLITE3_TEXT);
        $questsResult = $stmt->execute();
        $allQuests = [];
        while ($row = $questsResult->fetchArray(SQLITE3_ASSOC)) {
            if ($row['days_visible']) $row['days_visible'] = json_decode($row['days_visible'], true);
            if ($row['specific_dates']) $row['specific_dates'] = json_decode($row['specific_dates'], true);
            $allQuests[] = $row;
        }

        $visible = [];
        foreach ($allQuests as $quest) {
            if (isQuestVisibleOnDate($quest, $date, $db, $userId)) {
                $visible[] = $quest;
            }
        }

        $visible = applyReminderOverrides($visible, $db, $userId, $date);

        $db->close();
        echo json_encode(['quests' => $visible]);
        exit;
    }

    // ============ QUEST LINKS ============

    if ($method === 'GET' && $action === 'get_quest_links') {
        $qid = $_GET['quest_id'] ?? '';
        if (!$qid) { http_response_code(400); echo json_encode(['error' => 'quest_id required']); exit; }
        $db  = get_db();
        $uid = SQLite3::escapeString(get_user_id());
        $qesc = SQLite3::escapeString($qid);
        $res = $db->query("SELECT ql.id, ql.linked_quest_id, ql.wait_minutes, q.name, q.points, q.schedule, q.reminder_time, q.type FROM quest_links ql JOIN quests q ON q.id=ql.linked_quest_id WHERE ql.trigger_quest_id='$qesc' AND ql.user_id='$uid' ORDER BY ql.created_at ASC");
        $links = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) $links[] = $row;
        $db->close();
        echo json_encode(['links' => $links]);
        exit;
    }

    if ($method === 'POST' && $action === 'add_quest_link') {
        $data      = json_decode(file_get_contents('php://input'), true);
        $triggerId = $data['trigger_quest_id'] ?? '';
        $linkedId  = $data['linked_quest_id']  ?? '';
        $waitMins  = min(720, max(0, (int)($data['wait_minutes'] ?? 0)));
        if (!$triggerId || !$linkedId)      { http_response_code(400); echo json_encode(['error' => 'trigger_quest_id and linked_quest_id required']); exit; }
        if ($triggerId === $linkedId)        { http_response_code(400); echo json_encode(['error' => 'Cannot link a quest to itself']); exit; }
        $db   = get_db(); $userId = get_user_id();
        $stmt = $db->prepare("INSERT OR REPLACE INTO quest_links (user_id, trigger_quest_id, linked_quest_id, wait_minutes) VALUES (:u,:t,:l,:w)");
        $stmt->bindValue(':u', $userId,    SQLITE3_TEXT);    $stmt->bindValue(':t', $triggerId, SQLITE3_TEXT);
        $stmt->bindValue(':l', $linkedId,  SQLITE3_TEXT);    $stmt->bindValue(':w', $waitMins,  SQLITE3_INTEGER);
        $stmt->execute();
        $id = $db->lastInsertRowID();
        $db->close();
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    if ($method === 'POST' && $action === 'update_quest_link_wait') {
        $data    = json_decode(file_get_contents('php://input'), true);
        $linkId  = (int)($data['id'] ?? 0);
        $waitMins = min(720, max(0, (int)($data['wait_minutes'] ?? 0)));
        if (!$linkId) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
        $db  = get_db(); $uid = SQLite3::escapeString(get_user_id());
        $db->exec("UPDATE quest_links SET wait_minutes=$waitMins WHERE id=$linkId AND user_id='$uid'");
        $db->close();
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($method === 'DELETE' && $action === 'remove_quest_link') {
        $linkId = (int)($_GET['id'] ?? 0);
        if (!$linkId) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
        $db = get_db(); $uid = SQLite3::escapeString(get_user_id());
        $db->exec("DELETE FROM quest_links WHERE id=$linkId AND user_id='$uid'");
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

        $settingsFile = DATA_DIR . '/app_settings.json';
        $appSettings  = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
        $inflationEnabled = $appSettings['shop_inflation_enabled'] ?? true;

        $db->close();
        echo json_encode(['items' => $items, 'inflation_enabled' => (bool)$inflationEnabled]);
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

    // POST set_shop_inflation — toggle the inflation system on/off
    if ($method === 'POST' && $action === 'set_shop_inflation') {
        $data    = json_decode(file_get_contents('php://input'), true);
        $enabled = isset($data['enabled']) ? (bool)$data['enabled'] : true;
        $file    = DATA_DIR . '/app_settings.json';
        $s       = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        $s['shop_inflation_enabled'] = $enabled;
        file_put_contents($file, json_encode($s, JSON_PRETTY_PRINT));
        echo json_encode(['ok' => true, 'inflation_enabled' => $enabled]);
        exit;
    }

    // POST redeem reward – deduct points, applying inflation if enabled
    if ($method === 'POST' && $action === 'redeem') {
        $data     = json_decode(file_get_contents('php://input'), true);
        $db       = get_db();
        $userId   = get_user_id();
        $uid      = SQLite3::escapeString($userId);
        $today    = appToday();
        $itemName = $data['name'];
        $baseCost = (int)($data['base_cost'] ?? $data['cost']); // base_cost preferred

        $balance = (int)$db->querySingle("SELECT COALESCE(SUM(CAST(points AS INTEGER)), 0) FROM history WHERE user_id = '$uid'");

        // Calculate inflation if enabled
        $settingsFile = DATA_DIR . '/app_settings.json';
        $appSettings  = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
        $inflationEnabled = $appSettings['shop_inflation_enabled'] ?? true;

        $finalCost = $baseCost;
        if ($inflationEnabled) {
            $maxCost  = (int)$db->querySingle("SELECT COALESCE(MAX(cost), 0) FROM shop_items WHERE user_id = '$uid'");
            $threshold = $maxCost * 2;
            if ($maxCost > 0 && $balance > $threshold) {
                $inflation = (int)ceil(($balance - $threshold) / 2);
                $finalCost = $baseCost + $inflation; // never below base_cost since inflation >= 0
            }
        }

        if ($balance >= $finalCost) {
            $stmt = $db->prepare("INSERT INTO history (user_id, type, description, points, date) VALUES (:user, 'reward_redeem', :desc, :points, :date)");
            $stmt->bindValue(':user',   $userId,       SQLITE3_TEXT);
            $stmt->bindValue(':desc',   "Redeemed $itemName", SQLITE3_TEXT);
            $stmt->bindValue(':points', -$finalCost,   SQLITE3_INTEGER);
            $stmt->bindValue(':date',   $today,        SQLITE3_TEXT);
            $stmt->execute();
            check_achievements($db, $userId);
            $db->close();
            echo json_encode(['ok' => true, 'new_balance' => $balance - $finalCost, 'cost_paid' => $finalCost]);
        } else {
            $db->close();
            echo json_encode(['ok' => false, 'error' => 'Insufficient points', 'balance' => $balance, 'cost' => $finalCost]);
        }
        exit;
    }

    // ── Day presets ─────────────────────────────────────────────────────────────

    if ($method === 'GET' && $action === 'get_day_presets') {
        $db     = get_db();
        $uid    = SQLite3::escapeString(get_user_id());
        $result = $db->query("SELECT * FROM day_presets WHERE user_id = '$uid' ORDER BY created_at ASC");
        $out    = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) { unset($row['user_id']); $out[] = $row; }
        $db->close();
        echo json_encode(['presets' => $out]);
        exit;
    }

    if ($method === 'POST' && $action === 'add_day_preset') {
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');
        $days = trim($data['days'] ?? '');
        if (!$name || !$days) { http_response_code(400); echo json_encode(['error' => 'name and days required']); exit; }
        $db   = get_db();
        $stmt = $db->prepare("INSERT INTO day_presets (user_id, name, days) VALUES (:u, :n, :d)");
        $stmt->bindValue(':u', get_user_id(), SQLITE3_TEXT);
        $stmt->bindValue(':n', $name, SQLITE3_TEXT);
        $stmt->bindValue(':d', $days, SQLITE3_TEXT);
        $stmt->execute();
        $id = $db->lastInsertRowID();
        $db->close();
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    if ($method === 'DELETE' && $action === 'delete_day_preset') {
        $id  = (int)($_GET['id'] ?? 0);
        $db  = get_db();
        $uid = SQLite3::escapeString(get_user_id());
        $db->exec("DELETE FROM day_presets WHERE id = $id AND user_id = '$uid'");
        $db->close();
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Webhook / key management (continued from webhook block above) ────────────

    if ($method === 'POST' && $action === 'generate_webhook_key') {
        $file = DATA_DIR . '/app_settings.json';
        $s    = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        $s['webhook_api_key'] = bin2hex(random_bytes(24));
        file_put_contents($file, json_encode($s, JSON_PRETTY_PRINT));
        echo json_encode(['ok' => true, 'api_key' => $s['webhook_api_key']]);
        exit;
    }

    if ($method === 'GET' && $action === 'get_webhook_key') {
        $file = DATA_DIR . '/app_settings.json';
        $s    = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        echo json_encode(['api_key' => $s['webhook_api_key'] ?? null]);
        exit;
    }

    // ============ ACHIEVEMENTS ============

    // GET achievements – list all and which ones are earned
    if ($method === 'GET' && $action === 'achievements') {
        try {
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
            echo json_encode(['all' => $allAchievements, 'earned' => $earnedIds]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Achievements DB error: ' . $e->getMessage()]);
        }
        exit;
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

    // POST history
    if ($method === 'POST' && $action === 'history') {
        $data = json_decode(file_get_contents('php://input'), true);
        $entries = $data['entries'] ?? [];
        $db = get_db();
        $userId = get_user_id();
        $ins = $db->prepare("INSERT INTO history (user_id, type, description, points, date) VALUES (:u,:t,:d,:p,:dt)");
        foreach ($entries as $e) {
            $ins->bindValue(':u',  $userId,                            SQLITE3_TEXT);
            $ins->bindValue(':t',  $e['type'],                        SQLITE3_TEXT);
            $ins->bindValue(':d',  $e['description'],                 SQLITE3_TEXT);
            $ins->bindValue(':p',  (int)($e['points'] ?? 0),          SQLITE3_INTEGER);
            $ins->bindValue(':dt', $e['date'] ?? appToday(),       SQLITE3_TEXT);
            $ins->execute();
        }
        $db->close();
        echo json_encode(['ok' => true, 'saved' => count($entries)]);
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

        $today = appToday();
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

        // Only delete if the incoming list is not empty
        if (count($quests) > 0) {
            $stmt = $db->prepare("DELETE FROM quests WHERE user_id = :user AND from_calendar = 0");
            $stmt->bindValue(':user', $userId, SQLITE3_TEXT);
            $stmt->execute();
        } else {
            // Log a warning and abort
            error_log("WARNING: Attempted to delete all quests, but incoming list is empty – blocked.");
            $db->close();
            echo json_encode(['ok' => false, 'error' => 'Empty quest list – deletion blocked to prevent data loss']);
            exit;
        }

        $insert = $db->prepare("INSERT INTO quests (id, user_id, name, type, points, schedule, reminder_time, days_visible, specific_dates, group_name, group_step, from_calendar, priority, ha_completable)
            VALUES (:id, :user, :name, :type, :points, :schedule, :reminder, :days_visible, :specific_dates, :group_name, :group_step, 0, :priority, :ha_completable)");

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
            $insert->bindValue(':priority', !empty($quest['priority']) ? 1 : 0, SQLITE3_INTEGER);
            $insert->bindValue(':ha_completable', !empty($quest['ha_completable']) ? 1 : 0, SQLITE3_INTEGER);
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

    // POST request for sync calendar feeds
    if ($method === 'POST' && $action === 'sync_calendar_feeds') {
        $db = get_db();
        $userId = get_user_id();

        // Get all feeds
        $stmt = $db->prepare("SELECT * FROM calendar_feeds WHERE user_id = :uid ORDER BY created_at ASC");
        $stmt->bindValue(':uid', $userId, SQLITE3_TEXT);
        $feedsResult = $stmt->execute();
        $feeds = [];
        while ($row = $feedsResult->fetchArray(SQLITE3_ASSOC)) {
            $feeds[] = $row;
        }
        if (empty($feeds)) {
            echo json_encode(['ok' => true, 'count' => 0]);
            exit;
        }

        // Resolve today with timezone + 3am boundary
        $settingsFile = DATA_DIR . '/app_settings.json';
        $appSettings  = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
        $tz  = new DateTimeZone($appSettings['timezone'] ?? 'Europe/Amsterdam');
        $now = new DateTime('now', $tz);
        if ((int)$now->format('G') < 3) $now->modify('-1 day');
        $todayStr = $now->format('Y-m-d');

        // Fetch and parse each feed
        $quests = [];
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: QuestTracker/1.0\r\n"]]);
        foreach ($feeds as $feed) {
            $ical = @file_get_contents($feed['url'], false, $ctx);
            if (!$ical) continue;
            foreach (parseICalPHP($ical, $todayStr) as $ev) {
                $questName  = '📅 ' . ($ev['summary'] ?: 'Calendar Event');
                $startStr   = $ev['start']->format('D M d Y');   // "Mon Sep 07 2026" – matches JS toDateString()
                $endStr     = $ev['end']  ->format('D M d Y');
                $stableId   = 'cal_' . calQuestHashCode("{$questName}_{$startStr}_{$endStr}");
                $expiresISO = $ev['start']->format('Y-m-d');     // YYYY-MM-DD
                $reminder   = ltrim($ev['start']->format('g:i a'), '0');
                $quests[]   = ['id'=>$stableId,'name'=>$questName,'points'=>$ev['points'],
                               'reminder'=>$reminder,'expires'=>$expiresISO];
            }
        }

        // Delete old calendar quests for this user
        $del = $db->prepare("DELETE FROM quests WHERE user_id = :uid AND type = 'calendar'");
        $del->bindValue(':uid', $userId, SQLITE3_TEXT);
        $del->execute();

        // Insert new ones
        $ins = $db->prepare("INSERT INTO quests (id, user_id, name, type, points, reminder_time, expires, created_at)
                             VALUES (:id, :uid, :name, 'calendar', :points, :reminder, :expires, :created)");
        $nowStr = (new DateTime())->format('c');
        foreach ($quests as $q) {
            $ins->bindValue(':id',      $q['id'],       SQLITE3_TEXT);
            $ins->bindValue(':uid',     $userId,         SQLITE3_TEXT);
            $ins->bindValue(':name',    $q['name'],      SQLITE3_TEXT);
            $ins->bindValue(':points',  $q['points'],    SQLITE3_INTEGER);
            $ins->bindValue(':reminder',$q['reminder'],  SQLITE3_TEXT);
            $ins->bindValue(':expires', $q['expires'],   SQLITE3_TEXT);
            $ins->bindValue(':created', $nowStr,         SQLITE3_TEXT);
            $ins->execute();
        }
        $db->close();
        echo json_encode(['ok' => true, 'count' => count($quests)]);
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

    // ============ TIMEZONE ============

    // GET timezone setting
    if ($method === 'GET' && $action === 'get_timezone') {
        $file = DATA_DIR . '/app_settings.json';
        $s    = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        echo json_encode([
            'timezone'    => $s['timezone']    ?? 'Europe/Amsterdam',
            'time_format' => $s['time_format'] ?? '12h',
            'date_format' => $s['date_format'] ?? 'DD/MM/YYYY',
            'side_unlock_percent' => (int)($s['side_unlock_percent'] ?? SIDE_UNLOCK_DEFAULT),
            'side_unlock_default' => SIDE_UNLOCK_DEFAULT,
        ]);
        exit;
    }

    // POST set timezone (also accepts time_format and date_format)
    if ($method === 'POST' && $action === 'set_timezone') {
        $input = json_decode(file_get_contents('php://input'), true);
        $file  = DATA_DIR . '/app_settings.json';
        $s     = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        if (isset($input['timezone'])) {
            $tz = $input['timezone'];
            if (!in_array($tz, DateTimeZone::listIdentifiers())) {
                http_response_code(400); echo json_encode(['error' => 'Invalid timezone']); exit;
            }
            $s['timezone'] = $tz;
        }
        if (isset($input['time_format'])) $s['time_format'] = in_array($input['time_format'], ['12h','24h']) ? $input['time_format'] : '12h';
        if (isset($input['date_format'])) $s['date_format'] = in_array($input['date_format'], ['DD/MM/YYYY','MM/DD/YYYY','YYYY-MM-DD']) ? $input['date_format'] : 'DD/MM/YYYY';
        if (isset($input['side_unlock_percent'])) $s['side_unlock_percent'] = max(0, min(100, (int)$input['side_unlock_percent']));
        file_put_contents($file, json_encode($s, JSON_PRETTY_PRINT));
        echo json_encode(['ok' => true]);
        exit;
    }

    // ============ WEBHOOK / HOME ASSISTANT API ============

    $webhookKey  = (function() {
        $f = DATA_DIR . '/app_settings.json';
        $s = file_exists($f) ? json_decode(file_get_contents($f), true) : [];
        return $s['webhook_api_key'] ?? null;
    })();
    $webhookAuth = $webhookKey && hash_equals(
        $webhookKey,
        $_GET['api_key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '')
    );

    // GET ha_quests
    if ($method === 'GET' && $action === 'ha_quests') {
        if (!$webhookAuth) { http_response_code(401); echo json_encode(['error' => 'Invalid or missing api_key']); exit; }
        $db     = get_db(); $userId = get_user_id();
        $today  = appToday();
        $uid    = SQLite3::escapeString($userId);
        $result = $db->query("SELECT * FROM quests WHERE user_id='$uid' ORDER BY sort_order ASC");
        $out    = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!isQuestVisibleOnDate($row, $today, $db, $userId)) continue;
            $qid          = SQLite3::escapeString($row['id']);
            $row['completed'] = (bool)$db->querySingle("SELECT completed FROM quest_completions WHERE quest_id='$qid' AND user_id='$uid' AND completion_date='$today' AND completed=1");
            unset($row['user_id']);
            $out[] = $row;
        }
        $db->close();
        echo json_encode(['date' => $today, 'quests' => $out]);
        exit;
    }

    // GET ha_status
    if ($method === 'GET' && $action === 'ha_status') {
        if (!$webhookAuth) { http_response_code(401); echo json_encode(['error' => 'Invalid or missing api_key']); exit; }
        $db    = get_db(); $uid = SQLite3::escapeString(get_user_id());
        $today = appToday();
        echo json_encode([
            'date'            => $today,
            'total_points'    => (int)$db->querySingle("SELECT COALESCE(SUM(points),0) FROM history WHERE user_id='$uid' AND points>0"),
            'today_points'    => (int)$db->querySingle("SELECT COALESCE(SUM(points),0) FROM history WHERE user_id='$uid' AND date='$today' AND points>0"),
            'completed_today' => (int)$db->querySingle("SELECT COUNT(*) FROM quest_completions WHERE user_id='$uid' AND completion_date='$today' AND completed=1"),
            'active_days'     => (int)$db->querySingle("SELECT COUNT(DISTINCT completion_date) FROM quest_completions WHERE user_id='$uid' AND completed=1"),
        ]);
        $db->close();
        exit;
    }

    // GET ha_shop
    if ($method === 'GET' && $action === 'ha_shop') {
        if (!$webhookAuth) { http_response_code(401); echo json_encode(['error' => 'Invalid or missing api_key']); exit; }
        $db  = get_db(); $uid = SQLite3::escapeString(get_user_id());
        $res = $db->query("SELECT id, name, cost FROM shop_items WHERE user_id='$uid' ORDER BY cost ASC");
        $items = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) $items[] = $row;
        $balance   = (int)$db->querySingle("SELECT COALESCE(SUM(CAST(points AS INTEGER)),0) FROM history WHERE user_id='$uid'");
        $sFile     = DATA_DIR . '/app_settings.json';
        $sData     = file_exists($sFile) ? json_decode(file_get_contents($sFile), true) : [];
        $inflation = 0;
        if (($sData['shop_inflation_enabled'] ?? true) && !empty($items)) {
            $maxCost = max(array_column($items, 'cost'));
            if ($maxCost > 0 && $balance > $maxCost * 2) $inflation = (int)ceil(($balance - $maxCost * 2) / 2);
        }
        $db->close();
        echo json_encode(['items' => $items, 'inflation' => $inflation, 'balance' => $balance]);
        exit;
    }

    // POST ha_complete — mark a quest done (or undone) from Home Assistant / any webhook client.
    //   ha_completable quests: every call with completed=true pays out again (multi-complete per day).
    //   Normal quests: standard toggle, one completion per day.
    if ($method === 'POST' && $action === 'ha_complete') {
        if (!$webhookAuth) { http_response_code(401); echo json_encode(['error' => 'Invalid or missing api_key']); exit; }
        $data      = json_decode(file_get_contents('php://input'), true);
        $questId   = $data['quest_id']   ?? '';
        $questName = $data['quest_name'] ?? '';
        $points    = isset($data['points']) ? (int)$data['points'] : null;
        $completed = isset($data['completed']) ? (bool)$data['completed'] : true;
        if (!$questId) { http_response_code(400); echo json_encode(['error' => 'quest_id required']); exit; }
        $db     = get_db(); $userId = get_user_id();
        $uid    = SQLite3::escapeString($userId);
        $qid    = SQLite3::escapeString($questId);
        $today  = appToday();

        // Look the quest up so name/points don't have to be sent by the caller
        $q = $db->querySingle("SELECT name, points, COALESCE(ha_completable,0) AS ha FROM quests WHERE id='$qid' AND user_id='$uid'", true);
        if (!$q) { $db->close(); http_response_code(404); echo json_encode(['error' => 'Quest not found']); exit; }
        if ($questName === '') $questName = $q['name'];
        if ($points === null)  $points    = (int)$q['points'];
        $isHaOnly = (int)$q['ha'] === 1;
        $desc     = "Completed quest: " . $questName;

        $existing = $db->querySingle("SELECT id FROM quest_completions WHERE quest_id='$qid' AND user_id='$uid' AND completion_date='$today'");
        $alreadyDone = $existing ? (int)$db->querySingle("SELECT completed FROM quest_completions WHERE id=$existing") === 1 : false;

        if ($completed && $alreadyDone && !$isHaOnly) {
            // Normal quest already completed today: don't double-pay
            $db->close();
            echo json_encode(['ok' => true, 'quest_id' => $questId, 'completed' => true, 'note' => 'already completed today', 'reminder_updates' => []]);
            exit;
        }

        // Upsert today's completion row
        if ($existing) {
            $stmt = $db->prepare("UPDATE quest_completions SET completed=:c, points_earned=:p WHERE id=:id");
            $stmt->bindValue(':c', $completed ? 1 : 0, SQLITE3_INTEGER);
            $stmt->bindValue(':p', $completed ? $points : 0, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $existing, SQLITE3_INTEGER);
            $stmt->execute();
        } else {
            $stmt = $db->prepare("INSERT INTO quest_completions (quest_id,user_id,completion_date,completed,points_earned) VALUES (:q,:u,:d,:c,:p)");
            $stmt->bindValue(':q', $questId, SQLITE3_TEXT);  $stmt->bindValue(':u', $userId, SQLITE3_TEXT);
            $stmt->bindValue(':d', $today, SQLITE3_TEXT);    $stmt->bindValue(':c', $completed ? 1 : 0, SQLITE3_INTEGER);
            $stmt->bindValue(':p', $completed ? $points : 0, SQLITE3_INTEGER);
            $stmt->execute();
        }

        // History (prepared statements, no manual quote escaping)
        if ($completed) {
            $stmt = $db->prepare("INSERT INTO history (user_id,type,description,points,date) VALUES (:u,'quest_complete',:d,:p,:date)");
            $stmt->bindValue(':u', $userId, SQLITE3_TEXT);  $stmt->bindValue(':d', $desc, SQLITE3_TEXT);
            $stmt->bindValue(':p', $points, SQLITE3_INTEGER); $stmt->bindValue(':date', $today, SQLITE3_TEXT);
            $stmt->execute();
        } else {
            $stmt = $db->prepare("DELETE FROM history WHERE id=(SELECT id FROM history WHERE user_id=:u AND type='quest_complete' AND description=:d AND date=:date ORDER BY id DESC LIMIT 1)");
            $stmt->bindValue(':u', $userId, SQLITE3_TEXT);  $stmt->bindValue(':d', $desc, SQLITE3_TEXT);
            $stmt->bindValue(':date', $today, SQLITE3_TEXT);
            $stmt->execute();
        }

        // Linked quests: completion time + wait time -> today's reminder override
        $reminderUpdates = [];
        if ($completed) {
            $sFile = DATA_DIR . '/app_settings.json';
            $sData = file_exists($sFile) ? json_decode(file_get_contents($sFile), true) : [];
            $tz    = new DateTimeZone($sData['timezone'] ?? 'Europe/Amsterdam');
            $now   = new DateTime('now', $tz);
            $eod   = (new DateTime($today . ' 00:00:00', $tz))->modify('+1 day 2 hours 59 minutes');
            $lr    = $db->query("SELECT linked_quest_id, wait_minutes FROM quest_links WHERE trigger_quest_id='$qid' AND user_id='$uid'");
            while ($lnk = $lr->fetchArray(SQLITE3_ASSOC)) {
                $lqid = SQLite3::escapeString($lnk['linked_quest_id']);
                $t    = clone $now;
                $t->modify('+' . (int)$lnk['wait_minutes'] . ' minutes');
                if ($t > $eod) $t = clone $eod;
                $fmt  = $t->format('g:i a');
                $db->exec("INSERT OR REPLACE INTO daily_reminder_overrides (user_id,quest_id,date,reminder_time) VALUES ('$uid','$lqid','$today','$fmt')");
                $reminderUpdates[] = ['quest_id' => $lnk['linked_quest_id'], 'reminder_time' => $fmt];
            }
        }

        check_achievements($db, $userId);
        $db->close();
        echo json_encode(['ok' => true, 'quest_id' => $questId, 'completed' => $completed, 'reminder_updates' => $reminderUpdates]);
        exit;
    }

    // POST ha_add_quest
    if ($method === 'POST' && $action === 'ha_add_quest') {
        if (!$webhookAuth) { http_response_code(401); echo json_encode(['error' => 'Invalid or missing api_key']); exit; }
        $data     = json_decode(file_get_contents('php://input'), true);
        $name     = trim($data['name']     ?? '');
        $type     = $data['type']          ?? 'side';
        $points   = (int)($data['points']  ?? 1);
        $schedule = $data['schedule']      ?? 'Daily';
        $reminder = $data['reminder_time'] ?? null;
        if (!$name) { http_response_code(400); echo json_encode(['error' => 'name required']); exit; }
        $db   = get_db(); $id = 'ha-' . bin2hex(random_bytes(8));
        $stmt = $db->prepare("INSERT INTO quests (id,user_id,name,type,points,schedule,reminder_time,priority,ha_completable,created_at) VALUES (:id,:u,:n,:t,:p,:s,:r,:pri,:ha,CURRENT_TIMESTAMP)");
        $stmt->bindValue(':pri', !empty($data['priority']) ? 1 : 0, SQLITE3_INTEGER);
        $stmt->bindValue(':ha',  !empty($data['ha_completable']) ? 1 : 0, SQLITE3_INTEGER);
        $stmt->bindValue(':id',$id,SQLITE3_TEXT);      $stmt->bindValue(':u',get_user_id(),SQLITE3_TEXT);
        $stmt->bindValue(':n',$name,SQLITE3_TEXT);     $stmt->bindValue(':t',$type,SQLITE3_TEXT);
        $stmt->bindValue(':p',$points,SQLITE3_INTEGER);$stmt->bindValue(':s',$schedule,SQLITE3_TEXT);
        $stmt->bindValue(':r',$reminder,SQLITE3_TEXT);
        $stmt->execute();
        $db->close();
        echo json_encode(['ok' => true, 'id' => $id, 'name' => $name]);
        exit;
    }

    // POST ha_redeem
    if ($method === 'POST' && $action === 'ha_redeem') {
        if (!$webhookAuth) { http_response_code(401); echo json_encode(['error' => 'Invalid or missing api_key']); exit; }
        $data     = json_decode(file_get_contents('php://input'), true);
        $itemName = $data['name']      ?? '';
        $baseCost = (int)($data['base_cost'] ?? $data['cost'] ?? 0);
        if (!$itemName || !$baseCost) { http_response_code(400); echo json_encode(['error' => 'name and base_cost required']); exit; }
        $db      = get_db(); $userId = get_user_id();
        $uid     = SQLite3::escapeString($userId); $today = appToday();
        $balance = (int)$db->querySingle("SELECT COALESCE(SUM(CAST(points AS INTEGER)),0) FROM history WHERE user_id='$uid'");
        $sFile   = DATA_DIR . '/app_settings.json'; $sData = file_exists($sFile) ? json_decode(file_get_contents($sFile), true) : [];
        $final   = $baseCost;
        if ($sData['shop_inflation_enabled'] ?? true) {
            $maxCost = (int)$db->querySingle("SELECT COALESCE(MAX(cost),0) FROM shop_items WHERE user_id='$uid'");
            if ($maxCost > 0 && $balance > $maxCost * 2) $final = $baseCost + (int)ceil(($balance - $maxCost * 2) / 2);
        }
        if ($balance >= $final) {
            $stmt = $db->prepare("INSERT INTO history (user_id,type,description,points,date) VALUES (:u,'reward_redeem',:d,:p,:date)");
            $stmt->bindValue(':u',$userId,SQLITE3_TEXT); $stmt->bindValue(':d',"Redeemed $itemName",SQLITE3_TEXT);
            $stmt->bindValue(':p',-$final,SQLITE3_INTEGER); $stmt->bindValue(':date',$today,SQLITE3_TEXT);
            $stmt->execute(); check_achievements($db, $userId); $db->close();
            echo json_encode(['ok' => true, 'new_balance' => $balance - $final, 'cost_paid' => $final]);
        } else { $db->close(); echo json_encode(['ok' => false, 'error' => 'Insufficient points', 'balance' => $balance, 'cost' => $final]); }
        exit;
    }

    // DELETE ha_delete_quest
    if ($method === 'DELETE' && $action === 'ha_delete_quest') {
        if (!$webhookAuth) { http_response_code(401); echo json_encode(['error' => 'Invalid or missing api_key']); exit; }
        $questId = $_GET['quest_id'] ?? (json_decode(file_get_contents('php://input'), true)['quest_id'] ?? '');
        if (!$questId) { http_response_code(400); echo json_encode(['error' => 'quest_id required']); exit; }
        $db  = get_db(); $qid = SQLite3::escapeString($questId); $uid = SQLite3::escapeString(get_user_id());
        $db->exec("DELETE FROM quests WHERE id='$qid' AND user_id='$uid'");
        $db->exec("DELETE FROM quest_completions WHERE quest_id='$qid' AND user_id='$uid'");
        $db->exec("DELETE FROM quest_links WHERE (trigger_quest_id='$qid' OR linked_quest_id='$qid') AND user_id='$uid'");
        $db->close();
        echo json_encode(['ok' => true]);
        exit;
    }

    // ============ FALLBACK ============
    http_response_code(404);
    echo json_encode(['error' => 'No matching endpoint', 'action' => $action, 'method' => $method]);

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    exit;
}