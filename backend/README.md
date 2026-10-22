# 🎬 PikaShow Streaming Platform - PHP & MySQL Backend

A modern, high-performance PHP & MySQL backend and administration dashboard designed specifically for the PikaShow streaming mobile app.

---

## 🌟 Key Features

1. **First-Time Web Setup Wizard (`install/install.php`)**:
   - Automatically checks PHP extensions (`PDO`, `pdo_mysql`, `json`, `fileinfo`).
   - Interactive database configuration (Host, Port, Database name, Username, Password).
   - Real-time **"Test Connection & Inspect DB"** verification via AJAX.
   - **Intelligent Database Inspection**: If the database already exists, it preserves existing tables and data, and prompts you to install only missing tables.
   - Automatically creates the MySQL database & tables if they do not exist.
   - Preserves existing admin credentials and content if connecting to an existing database.
   - Optional starter catalog seeding from `mock_content.dart` (Bollywood movies, Hollywood movies, Web series with seasons & episodes, Live TV streams).


2. **Enterprise Backup & Disaster Recovery Studio (`backup.php`)**:
   - **One-Click "Back Up Everything (.ZIP)"**: Packages all MySQL tables, schemas, records, system settings, and all uploaded media files (`/uploads`) along with a metadata `manifest.json`.
   - **Standalone Database Backup (.SQL)**: Exports a pure PHP MySQL dump with zero reliance on external command-line binaries.
   - **Direct Browser Downloads**: Secure download of any generated backup archive with anti-directory-traversal protection.
   - **Upload & Restore on Live Server**: Admins can upload an existing `.zip` or `.sql` backup to restore server state with confirmation guards.
   - **Protected Storage**: Backups stored in `backend/backups/` secured by Apache `.htaccess` (`Require all denied`).

3. **New Server Setup via Backup File (`install/install.php`)**:
   - Install fresh on any new server directly using your `.zip` or `.sql` backup file.
   - Automatically initializes MySQL database, imports all schemas and data, unpacks uploaded media into `/uploads`, and sets up connection config.
   - Preserves existing admin account or allows setting new admin credentials during restore.

4. **Ultra-Modern Admin Dashboard**:
   - **Dark Theme & Glassmorphism Design**: Polished aesthetics with glowing accents and responsive sidebar.
   - **Dual Media Input**:
     - **Mode 1 - Direct URL**: Paste remote URLs (MP4, HLS `.m3u8`, Unsplash posters).
     - **Mode 2 - File Upload**: Drag-and-drop local image or video files with instant live preview.
   - **Built-in Stream Player**: Preview MP4 videos and Live TV HLS (`.m3u8`) streams directly inside the dashboard using native HTML5 and `Hls.js`.
   - **Full Catalog CRUD**:
     - Bollywood Movies
     - Hollywood Blockbusters
     - Web Series (multi-season and episode manager)
     - Live TV Channels (categorized by Sports, News, Entertainment)
   - **Spotlight Management**: Mark content as Hero/Promoted banner or Trending.

5. **REST API for Mobile App (`api/`)**:
   - JSON responses formatted to map 1:1 with Flutter `ContentItem`, `Season`, and `Episode` models.
   - Endpoints for listing, filtering, detail views, categories, search, and health checks.

---

## 🚀 Quick Start (Running Locally)

### Option 1: PHP Built-in Server (Zero Configuration)

Ensure your MySQL server is running (e.g., via XAMPP, Laragon, or standalone MySQL service).

```bash
# Navigate to the backend directory
cd backend

# Start the PHP server on port 8000
php -S 0.0.0.0:8000
```

Now open your web browser:
```
http://localhost:8000/
```
If this is the first time you are opening the backend, you will be redirected automatically to the **Installation Wizard**!

---

### Option 2: Apache / XAMPP / WAMP

1. Place or link the project in your web root (e.g. `C:\xampp\htdocs\remotecontrol\backend`).
2. Open your browser:
   ```
   http://localhost/remotecontrol/backend/
   ```

---

## 🛠️ First-Time Installation Steps

1. **Step 1 - System Check**: Verifies PHP 8.0+, PDO MySQL, JSON, and file permissions.
2. **Step 2 - Database Connection & Inspection**:
   - Host: `127.0.0.1` (or `localhost`)
   - Port: `3306`
   - Database Name: `pikashow_db` (or your existing database name)
   - MySQL Username: `root`
   - MySQL Password: *(leave blank if default XAMPP/WAMP)*
   - Click **"Test Connection & Inspect DB"** (or click **"Next"**).
   - **If database already exists**: The wizard will detect existing tables, keep all existing data intact, and ask if you want to install only the missing tables with selectable checkboxes!
   - **If all tables already exist**: It confirms that no table installation is needed and preserves your database.
3. **Step 3 - Admin User & Catalog**:
   - If an existing admin user is detected, you can check **"Keep Existing Admin Account(s)"** so you don't need to re-enter or change credentials.
   - If content items are detected, catalog seeding is automatically unchecked to protect existing content.
4. Click **"Complete Installation"**. The wizard installs only missing tables (if any), creates `config/config.php`, and redirects to the **Admin Login** page!

---

## 📱 Mobile App REST API Reference

All endpoints return UTF-8 JSON and support CORS headers:

| Endpoint | Method | Description | Parameters |
|---|---|---|---|
| `/api/content.php` | GET | List all content items | `?type=bollywood\|hollywood\|series\|liveTv`, `?trending=1`, `?promoted=1`, `?category=Sports`, `?search=query` |
| `/api/detail.php` | GET | Single content detail with seasons & episodes | `?id=prom_01` (also increments views counter) |
| `/api/categories.php` | GET | List genres, languages, qualities, channel categories | - |
| `/api/health.php` | GET | System status & database health | - |

### Example Flutter API Integration

In your Flutter app, you can fetch content dynamically:

```dart
import 'dart:convert';
import 'package:http/http.dart' as http;

Future<List<ContentItem>> fetchCatalog({String? type}) async {
  // Use http://10.0.2.2:8000 on Android Emulator or your PC LAN IP on physical device
  final url = Uri.parse('http://10.0.2.2:8000/api/content.php${type != null ? '?type=$type' : ''}');
  final response = await http.get(url);
  
  if (response.statusCode == 200) {
    final Map<String, dynamic> data = jsonDecode(response.body);
    final List items = data['data'];
    return items.map((item) => ContentItem.fromJson(item)).toList();
  } else {
    throw Exception('Failed to load catalog');
  }
}
```

---

## 🗄️ Database Schema Overview

- **`content_items`**: Core catalog entries (movies, series, live TV) with title, type, poster, banner, qualities, languages, genres, stream URL, rating, year, duration, views.
- **`seasons`**: Seasons linked to web series items (`content_id`).
- **`episodes`**: Individual episodes linked to `season_id` and `content_id` with titles, duration, thumbnails, and stream URLs.
- **`admin_users`**: Administrator login accounts with BCrypt hashed passwords and role management.
- **`settings`**: System configurations, maintenance mode toggles, and API keys.

---

## 🔒 Security Measures

- **Prepared Statements / PDO**: Zero SQL injection vulnerabilities across all queries.
- **BCrypt Password Hashing**: Passwords stored using `password_hash(..., PASSWORD_BCRYPT)`.
- **MIME & Extension Validation**: Uploaded media validated for safe extensions and types.
- **CSRF Token Protection**: Prevents cross-site request forgery attacks.
- **Sanitized Outputs**: HTML-escaped attributes and titles preventing XSS.
