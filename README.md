# 🎮 ADHD Friendly Gamified Schedule

#### A self‑hostable, ADHD‑friendly daily quest tracker with gamification (points, shop, achievements), iCal calendar sync, and full PWA support. Designed to turn chores and routines into a rewarding game, reducing executive dysfunction and procrastination.

---

## ✨ Features

- **🔐 Passkey (WebAuthn) login** – Passwordless, biometric login (Face ID, Touch ID, Windows Hello). QR code fallback for cross‑device. 



- **📋 Quest management** – Main & side quests with drag‑to‑reorder. Daily reset at midnight. 



- **📅 Calendar integration** – Fetch iCal feeds via a PHP proxy. Events with `DESCRIPTION:ical` appear as quests. Weekly recurring events are expanded for the next 30 days.



- **🏪 Custom shop** – Fully customisable rewards. Redeem points for your own treats.



- **📊 History & stats** – SQLite logs every completion, reward, and bonus. View detailed statistics and activity graphs.



- **🏆 Achievements** – Unlock achievements (quest count, streaks, hydration, early bird, night owl). Hidden future achievements are shown as `???`.



- **🎨 UI/UX** – Dark theme, responsive, PWA installable, collapsible sections, 30‑minute undo button, push notifications (when app is open).



- **⏰ Flexible scheduling** – Daily, weekly (specific days), bi‑weekly ("every other"), nth weekdays ("2nd & 4th Saturday"), specific dates with wildcards (`03/08/&&&&`, `&&/09/2026`), one‑time quests.



- **📈 Public stats page** – Password‑protected page to share progress with family or coaches.



- **🔒 Single‑user focus** – Designed for one person (no multi‑user complexity), but can easily be extended.
---
## 🚀 Quick Start

### Prerequisites

- PHP 7.4+ with `sqlite3`, `curl`, `json` extensions.
- Web server (Apache / Nginx) with HTTPS (required for WebAuthn).
- Domain name (for passkey).

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/xnewton3/ADHD-Friendly-Gamified-Schedule.git
   cd ADHD-Friendly-Gamified-Schedule
   ```
---
2. **Set up the web server**  
   Point your document root to the folder. Example for Apache:
   ```apache
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
---
3. **Set permissions**  
   The web server must be able to write to the `data/` directory.
   ```bash
   sudo chown -R www-data:www-data /your/path/to/ADHD-Friendly-Gamified-Schedule/data
   sudo chmod 775 /your/path/to/ADHD-Friendly-Gamified-Schedule/data
   ```
---
4. **Configure registration secret**  
   Edit `api.php` and change:
   ```php
   define('REGISTER_SECRET', 'your-secret-key-here'); // CHANGE THIS!
   ```
---
5. **Enable required PHP extensions** (Ubuntu/Debian)
   ```bash
   sudo apt install php8.1-sqlite3 php8.1-curl
   sudo systemctl restart apache2   # or php-fpm
   ```
---
6. **Visit your site** and register a passkey (enter the secret from step 4).
---
7. **(Optional) Install as PWA** – On mobile, use "Add to Home Screen".
---
## 📁 File Structure

```
ADHD-Friendly-Gamified-Schedule/
├── index.html          # Main quest view
├── manager.html        # Manage quests, shop, iCal feeds
├── history.html        # History viewer
├── achievements.html   # Achievement gallery
├── stats.html          # Public statistics (password protected)
├── api.php             # PHP backend (SQLite, iCal proxy)
├── webauthn.js         # Passkey helper
├── manifest.json       # PWA manifest
├── data/               # Created automatically
│   ├── data.db         # SQLite database (all app data)
│   └── avatar.png      # Profile picture (optional)
└── README.md
```

---
## 🔧 Configuration

### Changing the public stats password

Edit `stats.html`, change the `accessCode` variable (line 85):
```javascript
accessCode: 'your-new-code',
```

### Adding iCal feeds

1. Go to **Manager** → **Calendar Feeds (iCal)**.
2. Add a name and the URL of your `.ics` feed.
3. Events will only appear if their `DESCRIPTION` field contains the word `ical` (case‑insensitive).  
   *Tip: In your calendar app, add `ical` to the event description to make it a quest.*

### One‑time quests

 Set schedule to **One‑time** when creating/editing a quest. After completion, it will be deleted automatically at midnight.

### Progressive quest chains (e.g., "Drink water (1x)", "(2x)", …)

The system automatically groups quests whose names end with `(1x)`, `(2x)`, etc. Only the lowest‑numbered incomplete quest in the chain is shown. Completing it unlocks the next step.

---
## 🧑‍💻 Development / Customisation

- All data is stored in `data/data.db`. You can inspect it with any SQLite browser.
- The frontend uses vanilla HTML/CSS/JS (no build step). Edit directly.
- The backend is a single `api.php` file – easy to modify.
---
## 📜 License

- MIT License – feel free to use, modify, and distribute. See `LICENSE` file.
---
## 🙏 Acknowledgements

Built with neurodivergent needs in mind:
- ✅ Forgiving mechanics (missed quests give bonus next day, undo button)
- 🎮 Gamification to reward consistency, not perfection
- 📅 Calendar sync to offload executive function
- 🏆 Achievements for positive reinforcement

Enjoy turning your routine into a game! 🎮

---
###### This project was built with help of DeepSeek. 

###### I don't care about anyone's opinion on that it was built with help of AI, this is merely a footnote that it was.
