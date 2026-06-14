# 🎮 ADHD Friendly Gamified Schedule

#### A self‑hostable, ADHD‑friendly daily quest tracker with gamification (points, shop, achievements), iCal calendar sync, and full PWA support. Designed to turn chores and routines into a rewarding game, reducing executive dysfunction and procrastination.

---

## ✨ Features

- **🔐 Password login** – Simple password‑based login with session + 21‑day persistent cookie.
  Password is set via `.env` – no hardcoded credentials.


- **📋 Quest management** – Main & side quests with drag‑to‑reorder. Daily reset at 3am (configurable).
  One‑time quests are automatically deleted after completion (at next reset).


- **📅 Calendar integration** – Fetch iCal feeds via a PHP proxy. Events with `DESCRIPTION:ical` appear as quests.
  Weekly recurring events are expanded for the next 30 days.


- **🏪 Custom shop** – Fully customisable rewards. Redeem points for your own treats.
  Shop items are sorted by cost (cheapest first) and edit uses a modal (no more prompts).


- **📊 History & stats** – SQLite logs every completion, reward, and bonus.
  View detailed statistics, daily points chart (last 30 days), and streak counter.
  Stats page now remembers login for 30 days (optional logout button).


- **🏆 Achievements** – Unlock achievements (quest count, streaks, hydration, early bird, night owl).
  Hydration achievements count **distinct days** (not total completions).
  Hidden future achievements are shown as `???`.


- **🎨 UI/UX** – Dark theme, responsive, PWA installable, collapsible sections, 30‑minute undo button, push notifications (when app is open).
  Multiple built‑in themes (Default, Coffee, Midnight, Ocean, Sunset, Forest) + custom theme creator.


- **⏰ Flexible scheduling** – No more typing complex schedules. Use the visual schedule picker in `manager.html` to set:
  * One‑time
  * Daily
  * Weekly (specific days)
  * Every other week
  * Monthly (day of month)
  * Monthly (nth weekday)
  * Yearly (specific date)


- **📈 Public stats page** – Password‑protected page (access code fetched from server, never hardcoded).
  Supports persistent login (30 days) via `localStorage`. Share progress with family or coaches.


- **🔒 Single‑user focus** – Designed for one person (no multi‑user complexity), but can easily be extended.

---

## 🚀 Quick Start

### Prerequisites

- PHP 7.4+ with `sqlite3`, `curl`, `json` extensions.
- Web server (Apache / Nginx) with HTTPS recommended.
- Domain name (optional, but recommended for stable cookies/sessions).

### Installation

1. **Clone the repository**
   ```
   git clone https://github.com/xnewton3/ADHD-Friendly-Gamified-Schedule.git
   cd ADHD-Friendly-Gamified-Schedule
   ```

2. **Set up the web server**
   Point your document root to the folder. Example for Apache:
   ```
   <VirtualHost *:443>
      ServerName quest.yourdomain.com
      DocumentRoot /your/path/to/ADHD-Friendly-Gamified-Schedule
      <Directory /your/path/to/ADHD-Friendly-Gamified-Schedule>
         Options -Indexes +FollowSymLinks
         AllowOverride All
         Require all granted
      </Directory>
      SSLEngine on
      SSLCertificateFile /path/to/cert.pem
      SSLCertificateKeyFile /path/to/key.pem
   </VirtualHost>
   ```

3. **Set permissions**
   The web server must be able to write to the `data/` directory.
   ```
   sudo chown -R www-data:www-data /your/path/to/ADHD-Friendly-Gamified-Schedule/data
   sudo chmod 775 /your/path/to/ADHD-Friendly-Gamified-Schedule/data
   ```

4. **Create a `.env` file** at `/var/www/.env` (outside the web root) with:
   ```
   PASSKEY_CREATION_KEY=your-login-password-here
   STATS_ACCESS_CODE=your-stats-password-here
   ```
   `api.php` reads this file directly from `/var/www/.env` and will refuse to run if it's missing.

5. **Enable required PHP extensions** (Ubuntu/Debian)
   ```
   sudo apt install php8.1-sqlite3 php8.1-curl
   sudo systemctl restart apache2   # or php-fpm
   ```

6. **Visit your site** and log in using the password set in `PASSKEY_CREATION_KEY`.

7. **(Optional) Install as PWA** – On mobile, use "Add to Home Screen".

---

## 📁 File Structure

```
ADHD-Friendly-Gamified-Schedule/
├── index.html          # Main quest view (login + dashboard)
├── manager.html         # Manage quests, shop, iCal feeds, themes
├── history.html         # History viewer
├── achievements.html     # Achievement gallery
├── stats.html           # Public statistics (password protected)
├── api.php              # PHP backend (SQLite, iCal proxy, .env loader)
├── theme-loader.js       # Dynamic theme loader
├── manifest.json         # PWA manifest
├── data/                 # Created automatically
│   ├── data.db           # SQLite database (all app data)
│   ├── avatar.png         # Profile picture (optional)
│   └── themes.json        # Saved theme preferences
├── css/                  # Theme CSS files (theme-*.css)
└── README.md
```

> ⚠️ `.env` lives **outside** this folder, at `/var/www/.env` – not inside the project directory.

---

## 🔧 Configuration

### Changing the login password

Edit `/var/www/.env`:
```
PASSKEY_CREATION_KEY=your-new-password
```

### Changing the stats access code

Same `.env` file:
```
STATS_ACCESS_CODE=your-new-code
```
The stats page fetches this code from the server – no hardcoding in HTML.

### Adding iCal feeds

1. Go to **Manager** → **Calendar Feeds (iCal)**.
2. Add a name and the URL of your `.ics` feed.
3. Events will only appear if their `DESCRIPTION` field contains the word `ical` (case‑insensitive).
   * *Tip: In your calendar app, add `ical` to the event description to make it a quest.*

### One‑time quests

Select **One‑time** in the schedule picker when creating/editing a quest. After completion, it will be deleted automatically at the next daily reset (3am).

### Progressive quest chains (e.g., "Drink water (1x)", "(2x)", …)

The system automatically groups quests whose names end with `(1x)`, `(2x)`, etc. Only the lowest‑numbered incomplete quest in the chain is shown. Completing it unlocks the next step. \
\
*Note: Progressive Quest Chains require the quest names to be identical, e.g:*\
*Drink Water (1x), Drink Water (2x), Drink Water (3x), etc.*\

Just lil a head's up: Apostrophe's (`'`) will break the quest system.

---

## 🧑‍💻 Development / Customisation

- All data is stored in `data/data.db`. You can inspect it with any SQLite browser.
- The frontend uses vanilla HTML/CSS/JS (no build step). Edit directly.
- The backend is a single `api.php` file – easy to modify.
- Themes are CSS files in the `css/` folder. Add new themes by creating `theme-*.css` and adding an entry in `data/themes.json`.

---

## 📜 License

- MIT License – feel free to use, modify, and distribute. See `LICENSE` file.

---

## 🙏 Acknowledgements

Built with neurodivergent needs in mind:
- ✅ Forgiving mechanics (missed quests give bonus points the next day, undo button)
- 🎮 Gamification to reward consistency, not perfection
- 📅 Calendar sync to offload executive function
- 🏆 Achievements for positive reinforcement

Enjoy turning your routine into a game! 🎮

---

###### This project was built with help of DeepSeek.

###### I don't care about anyone's opinion on that it was built with help of AI, this is merely a footnote that it was.