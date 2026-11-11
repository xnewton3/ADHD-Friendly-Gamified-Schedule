# 🎮 Quest Tracker

A self-hosted quest / chore / self-care tracker.

Complete quests → earn points → spend them in your own shop.

Everything lives on the server (PHP + SQLite), so every device stays in sync.

---

## ✨ What it does

### 📋 Quests

- **Main** quests, **side** quests (unlock after you finish a share of your main quests, 25% by default, adjustable 0–100%), and **calendar** quests (from iCal feeds)

- **Day selector:** Daily · One-Time · Select Days · Custom Date

- **Week selector** (for Daily and Select Days): every week · odd · even · 1st / 2nd / 3rd / 4th week of 4

- 📅 **Weekly quest**: any schedule, but only completable once per Mon–Sun week

- 🔴 **Priority quest**: highlighted in the danger colour

- ⚡ **HA-only quest**: no checkbox; only completes through Home Assistant (can be completed more than once a day)

- 🔗 **Group links**: finish quest A and linked quests get a new reminder time of *completion time + wait time* (up to 12 hrs)

- Grouped names like `Drink Water (1x)` display as `Drink Water (1/4)`

- 📌 **Day presets**: save named day combos ("Weekdays", "DD Days") as one-tap buttons

---

### 🛒 Shop

- Add your own rewards with a point cost

- 💹 **Inflation (optional):** once your points are above 2× your most expensive item, every price rises by half the excess (rounded up). Prices never drop below what you set.

---

### 🏆 Achievements, stats & history

- 40+ achievements, each worth at most 50 pts

- 30-day completion log (keeps quests you've since deleted)

- Streaks, charts, full history page

---

### 🎨 Settings (Manager page)

- Timezone (quests reset at **3:00 AM** in that timezone)

- 🔓 Side quest unlock slider (0–100% of main quests, with a one-tap "system default" button)

- 12h / 24h time and DD/MM/YYYY · MM/DD/YYYY · YYYY-MM-DD dates

- Themes (synced between devices), profile picture, iCal feeds, API key

---

## 📆 Calendar quests (iCal)

Add your calendar's iCal URL in **Manager → iCal feeds**.

An event only becomes a quest if its **description** contains the word `ical`.

Add `,N` for points. **Everything after it is ignored.**

| Description | Result |
|-------------|--------|
| `ical,1.anything here` | 1 point |
| `ical. anything here` | 1 point (default) |
| `ical,5. anything here` | 5 points |

- Only the standalone word counts, so "tech**nical**" won't trigger it

- Not case-sensitive, and `ical:5` (the old style) still works

- Quests appear on the day of the event; the next 30 days are imported

- All parsing happens in `api.php`, so the main page and the Manager page always agree

---

## 🛠️ Setup (Apache)

Built and tested on **Apache 2.4 + PHP 7.3 or newer** with the SQLite3 extension. Other web servers are untested.

### 1. Upload the site

Put the files in your site's root folder (for example `/var/www/html`).

### 2. Create the `.env` file

It goes in the **parent folder** of the site root, so the website never serves it:

```
/var/www/.env
```

```
PASSKEY_CREATION_KEY_ROOS=your-login-password
STATS_ACCESS_CODE_ROOS=your-stats-code
```

- `PASSKEY_CREATION_KEY_ROOS` is your **login password**. The name is left over from the old passkey login. There are no passkeys any more.

- `STATS_ACCESS_CODE_ROOS` lets the stats page read data without a full login.

### 3. Make `data/` writable

```bash
mkdir -p data
chown -R www-data:www-data data
```

The database (`data/data.db`) and settings (`data/app_settings.json`) are created automatically.

### 4. Check the data folder is private ⚠️

`api.php` writes `data/.htaccess` (`Require all denied`) for you.

Open `https://your-site/data/data.db` in a browser. It **must** say 403 Forbidden.

If it downloads a file, Apache is ignoring `.htaccess` (`AllowOverride None`). Fix that, or move `data/` outside the web root.

### 5. Keep PHP updated

Security releases do happen. Update PHP regularly (`apt update && apt upgrade`).

---

## 📁 Files

```
index.html          main quest screen
manager.html        quests, shop, presets, themes, timezone, API key
stats.html          statistics + daily log
history.html        full history
achievements.html   achievements
api.php             ALL server-side logic
theme-loader.js     theme loading
sw.js, manifest.json   PWA bits
css/                themes
data/               database + settings (private!)
migrate.php / fix_blast_points.php   one-time scripts, delete after use
```

---

## 🏠 Home Assistant / external API

Create a key in **Manager → Webhook / External API**.

Send it as a header `X-API-Key: <key>` (preferred) or as `?api_key=<key>`.

| Method | Action | What it does |
|--------|--------|--------------|
| GET | `ha_quests` | Today's quests (with ids + completed flag) |
| GET | `ha_status` | Points total / today, completed today, active days |
| GET | `ha_shop` | Shop items, current inflation, your balance |
| POST | `ha_complete` | Complete a quest: `{"quest_id": "..."}` |
| POST | `ha_redeem` | Buy an item: `{"name": "...", "base_cost": 20}` |
| POST | `ha_add_quest` | New quest: `name`, `type`, `points`, `schedule`, `priority`, `ha_completable` |
| DELETE | `ha_delete_quest` | `?quest_id=...` |

Example: `https://example.com/api.php?action=ha_quests`

The Manager page generates a ready-to-paste YAML example with your key in it.

### Ideas

- 🔘 Zigbee button → `rest_command` → completes an ⚡ HA-only quest (pays out every press)

- 🧺 Washer + dryer sensors → an automation that calls `ha_complete` when both have run

- 🃏 Custom card: read `ha_quests`, `ha_status` and `ha_shop`, and call `ha_complete` / `ha_redeem`. Browser-based cards need the CORS header from the included `.htaccess` (`X-API-Key`).

> 🔑 The API key can complete quests and spend points. Treat it like a password.

---

## 🗓️ Schedule strings (what's stored)

| Stored as | Meaning |
|-----------|---------|
| `Daily` | Every day |
| `odd Daily` | Every day, odd weeks only |
| `One-time` | Once, then it's gone |
| `days:Monday,Friday` | Every Monday and Friday |
| `even days:Tuesday` | Tuesdays of even weeks |
| `Weekly:days:Thursday,Sunday` | Thu or Sun, once per week |
| `15/&&/&&&&` | The 15th of every month |

Older `Weekend` and `DD` schedules still work.

---

## 🔒 Security notes

- Login uses a signed cookie that can't be forged. Changing the password logs every device out.

- `data/` holds your database and API key. Keep it private (see setup step 4).# 🎮 Quest Tracker

A self-hosted quest / chore / self-care tracker.

Complete quests → earn points → spend them in your own shop.

Everything lives on the server (PHP + SQLite), so every device stays in sync.

---

## ✨ What it does

### 📋 Quests

- **Main** quests, **side** quests (unlock after you finish a share of your main quests, 25% by default, adjustable 0–100%), and **calendar** quests (from iCal feeds)

- **Day selector:** Daily · One-Time · Select Days · Custom Date

- **Week selector** (for Daily and Select Days): every week · odd · even · 1st / 2nd / 3rd / 4th week of 4

- 📅 **Weekly quest**: any schedule, but only completable once per Mon–Sun week

- 🔴 **Priority quest**: highlighted in the danger colour

- ⚡ **HA-only quest**: no checkbox; only completes through Home Assistant (can be completed more than once a day)

- 🔗 **Group links**: finish quest A and linked quests get a new reminder time of *completion time + wait time* (up to 12 hrs)

- Grouped names like `Drink Water (1x)` display as `Drink Water (1/4)`

- 📌 **Day presets**: save named day combos ("Weekdays", "DD Days") as one-tap buttons

---

### 🛒 Shop

- Add your own rewards with a point cost

- 💹 **Inflation (optional):** once your points are above 2× your most expensive item, every price rises by half the excess (rounded up). Prices never drop below what you set.

---

### 🏆 Achievements, stats & history

- 40+ achievements, each worth at most 50 pts

- 30-day completion log (keeps quests you've since deleted)

- Streaks, charts, full history page

---

### 🎨 Settings (Manager page)

- Timezone (quests reset at **3:00 AM** in that timezone)

- 🔓 Side quest unlock slider (0–100% of main quests, with a one-tap "system default" button)

- 12h / 24h time and DD/MM/YYYY · MM/DD/YYYY · YYYY-MM-DD dates

- Themes (synced between devices), profile picture, iCal feeds, API key

---

## 📆 Calendar quests (iCal)

Add your calendar's iCal URL in **Manager → iCal feeds**.

An event only becomes a quest if its **description** contains the word `ical`.

Add `,N` for points. **Everything after it is ignored.**

| Description | Result |
|-------------|--------|
| `ical,1.anything here` | 1 point |
| `ical. anything here` | 1 point (default) |
| `ical,5. anything here` | 5 points |

- Only the standalone word counts, so "tech**nical**" won't trigger it

- Not case-sensitive, and `ical:5` (the old style) still works

- Quests appear on the day of the event; the next 30 days are imported

- All parsing happens in `api.php`, so the main page and the Manager page always agree

---

## 🛠️ Setup (Apache)

Built and tested on **Apache 2.4 + PHP 7.3 or newer** with the SQLite3 extension. Other web servers are untested.

### 1. Upload the site

Put the files in your site's root folder (for example `/var/www/html`).

### 2. Create the `.env` file

It goes in the **parent folder** of the site root, so the website never serves it:

```
/var/www/.env
```

```
PASSKEY_CREATION_KEY_ROOS=your-login-password
STATS_ACCESS_CODE_ROOS=your-stats-code
```

- `PASSKEY_CREATION_KEY_ROOS` is your **login password**. The name is left over from the old passkey login. There are no passkeys any more.

- `STATS_ACCESS_CODE_ROOS` lets the stats page read data without a full login.

### 3. Make `data/` writable

```bash
mkdir -p data
chown -R www-data:www-data data
```

The database (`data/data.db`) and settings (`data/app_settings.json`) are created automatically.

### 4. Check the data folder is private ⚠️

`api.php` writes `data/.htaccess` (`Require all denied`) for you.

Open `https://your-site/data/data.db` in a browser. It **must** say 403 Forbidden.

If it downloads a file, Apache is ignoring `.htaccess` (`AllowOverride None`). Fix that, or move `data/` outside the web root.

### 5. Keep PHP updated

Security releases do happen. Update PHP regularly (`apt update && apt upgrade`).

---

## 📁 Files

```
index.html          main quest screen
manager.html        quests, shop, presets, themes, timezone, API key
stats.html          statistics + daily log
history.html        full history
achievements.html   achievements
api.php             ALL server-side logic
theme-loader.js     theme loading
sw.js, manifest.json   PWA bits
css/                themes
data/               database + settings (private!)
migrate.php / fix_blast_points.php   one-time scripts, delete after use
```

---

## 🏠 Home Assistant / external API

Create a key in **Manager → Webhook / External API**.

Send it as a header `X-API-Key: <key>` (preferred) or as `?api_key=<key>`.

| Method | Action | What it does |
|--------|--------|--------------|
| GET | `ha_quests` | Today's quests (with ids + completed flag) |
| GET | `ha_status` | Points total / today, completed today, active days |
| GET | `ha_shop` | Shop items, current inflation, your balance |
| POST | `ha_complete` | Complete a quest: `{"quest_id": "..."}` |
| POST | `ha_redeem` | Buy an item: `{"name": "...", "base_cost": 20}` |
| POST | `ha_add_quest` | New quest: `name`, `type`, `points`, `schedule`, `priority`, `ha_completable` |
| DELETE | `ha_delete_quest` | `?quest_id=...` |

Example: `https://example.com/api.php?action=ha_quests`

The Manager page generates a ready-to-paste YAML example with your key in it.

### Ideas

- 🔘 Zigbee button → `rest_command` → completes an ⚡ HA-only quest (pays out every press)

- 🧺 Washer + dryer sensors → an automation that calls `ha_complete` when both have run

- 🃏 Custom card: read `ha_quests`, `ha_status` and `ha_shop`, and call `ha_complete` / `ha_redeem`. Browser-based cards need the CORS header from the included `.htaccess` (`X-API-Key`).

> 🔑 The API key can complete quests and spend points. Treat it like a password.

---

## 🗓️ Schedule strings (what's stored)

| Stored as | Meaning |
|-----------|---------|
| `Daily` | Every day |
| `odd Daily` | Every day, odd weeks only |
| `One-time` | Once, then it's gone |
| `days:Monday,Friday` | Every Monday and Friday |
| `even days:Tuesday` | Tuesdays of even weeks |
| `Weekly:days:Thursday,Sunday` | Thu or Sun, once per week |
| `15/&&/&&&&` | The 15th of every month |

Older `Weekend` and `DD` schedules still work.

---

## 🔒 Security notes

- Login uses a signed cookie that can't be forged. Changing the password logs every device out.

- `data/` holds your database and API key. Keep it private (see setup step 4).

- Only the website files go in the web root. `.env` stays one level up.

---

<sub>This project was built with AI assistance from DeepSeek and Claude (Anthropic). Review the code before trusting it with anything important.</sub>

- Only the website files go in the web root. `.env` stays one level up.

---

<sub>This project was built with AI assistance from DeepSeek and Claude (Anthropic). Review the code before trusting it with anything important.</sub>