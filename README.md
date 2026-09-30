# PitchVault - Private Video-Sharing Web Application

A full-featured **private video-sharing web application** built with **Plain PHP, MySQL (PDO), HTML5 Video API, Vanilla JavaScript, and Tailwind CSS**, tailored for hosting on **WAMP** (Windows, Apache, MySQL, PHP).

---

## Key Capabilities & Features

1. **Email-Based Access Control (Google Drive Style)**:
   - No passwords or registration required for viewers.
   - Video URLs are protected. Accessing a video URL prompts the viewer for their email.
   - Access is granted only if the viewer's email has been added to the access list by the admin.
   - Server-side authorization enforced via PHP sessions and database verification.

2. **Protected MP4 Streaming Endpoint (`video-stream.php`)**:
   - MP4 video files are shielded from direct public download.
   - Implements **HTTP 206 Partial Content** and `Range` headers so seeking, scrubbing, and buffering work smoothly in HTML5 Video.

3. **Granular HTML5 Video Event Tracking**:
   - Automatically records discrete player events: `Play`, `Pause`, `Seek`, `Resume`, `Progress`, `Video Ended`.
   - Tracks precise watch time deltas (excluding scrub jumps), last playback position, and completion status.
   - **Immutable Event Logs**: Historical events are never overwritten. Every viewing generates a separate, distinct session entry.

4. **SaaS Admin Dashboard**:
   - **Project Structure**: Group videos logically into Projects (`Project` &rarr; `Video 1`, `Video 2`, `Video 3`).
   - **Asset Management**: Upload MP4 videos and thumbnail images.
   - **Google Drive-Inspired Share Modal**: Add/remove authorized emails with instant AJAX feedback and copyable private share links.
   - **Deep Analytics & Event Timeline**: Detailed viewer activity feed showing who watched each video, total session count, watch %, and a timestamped event timeline (`09:30:12 Play 00:00`, `09:31:25 Pause 01:13`, `09:33:18 Seek 02:27 → 04:05`).

---

## Directory Structure

```text
pitching-videos/
├── config/
│   └── database.php             # PDO database connection configuration
├── includes/
│   ├── auth.php                 # Session management, authorization helpers & CSRF utilities
│   ├── header.php               # Tailwind CSS, navbar & global header layout
│   └── footer.php               # Footer layout and scripts
├── uploads/
│   ├── videos/                  # Stored MP4 video files (Includes sample_pitch.mp4)
│   └── thumbnails/              # Stored video poster images (Includes sample_thumbnail.jpg)
├── admin/
│   ├── login.php                # Admin login screen (admin@example.com / admin123)
│   ├── index.php                # Admin Dashboard (Project tree, stats cards, recent sessions)
│   ├── projects.php             # Project CRUD manager
│   ├── video-upload.php         # Single unified video page (Upload, edit metadata, copy share URL, manage email access, stats & delete)
│   ├── video-details.php        # Legacy route (Auto-redirects to video-upload.php)
│   ├── analytics.php            # Analytics breakdown, viewer metrics & session event timelines
│   └── logout.php               # Admin logout handler
├── ajax/
│   ├── track_event.php          # AJAX endpoint for logging player pings into video_events
│   └── share_access.php         # AJAX endpoint for managing viewer emails
├── watch.php                    # Public/Private Video Page with Email Gate modal & HTML5 tracker
├── project.php                  # Public Project Showcase Page (View all videos in a project)
├── video-stream.php             # Protected PHP video streaming server with HTTP Range support
├── database.sql                 # Complete MySQL database schema & sample seed data
├── index.php                    # Application landing page / portal redirect
└── README.md                    # Setup & installation guide
```

---

## Installation & WAMP Setup Instructions

### Prerequisites
- [WAMP Server](https://www.wampserver.com/en/) installed on Windows (Apache, PHP 8.x, MySQL/MariaDB).
- phpMyAdmin accessible at `http://localhost/phpmyadmin/`.

---

### Step 1: Copy Application Files to WAMP `www` Directory
Copy the `pitching-videos` project folder into your WAMP `www` folder:
```text
C:\wamp64\www\pitching-videos
```

---

### Step 2: Database Setup via phpMyAdmin
1. Open your browser and navigate to **phpMyAdmin**: `http://localhost/phpmyadmin/`.
2. Click **Databases** tab and create a new database named:
   ```text
   pitching_videos_db
   ```
3. Select `pitching_videos_db` from the left sidebar.
4. Go to the **Import** tab.
5. Click **Choose File** and select `database.sql` from your project folder:
   `C:\wamp64\www\pitching-videos\database.sql`
6. Click **Import** at the bottom to execute the SQL script.

---

### Step 3: Configure Database Connection (Optional)
The database connection configuration is stored in `config/database.php`:
```php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'pitching_videos_db');
define('DB_USER', 'root');
define('DB_PASS', '');
```
*If your WAMP MySQL setup requires a password for `root`, update `DB_PASS` accordingly.*

---

### Step 4: Verify Folder Permissions for Uploads
Ensure the Apache web server user has write permissions for the uploads folders:
- `C:\wamp64\www\pitching-videos\uploads\videos\`
- `C:\wamp64\www\pitching-videos\uploads\thumbnails\`

---

## How to Test & Run the Application

### 1. Admin Portal Access
- Open browser to: `http://localhost/pitching-videos/admin/login.php`
- **Default Admin Credentials**:
  - **Email**: `admin@example.com`
  - **Password**: `admin123`

### 2. Managing Projects & Uploading Videos
1. Go to **Dashboard** &rarr; **Projects** to create or view projects.
2. Click **Upload Video** to upload an MP4 video file and thumbnail image.
3. Add initial allowed email addresses (e.g. `john@example.com`, `investor@firm.com`).

### 3. Viewing & Sharing Project Links
1. Navigate to **Projects** or **Dashboard** in the Admin Portal.
2. Click **View Project** or **Copy Link** for any project card.
3. The View Project Link format is:
   `http://localhost/pitching-videos/project.php?id=1`
4. Viewers visiting this link must verify their email with a 6-digit OTP code to view videos and launch video playback.

### 4. Sharing Private Video URLs
1. Open any video in **Admin Dashboard** &rarr; **Manage & Share**.
2. Click **Copy Private Link** or use the **Share Video** dialog.
3. The generated private URL looks like:
   `http://localhost/pitching-videos/watch.php?v=v_demo_7f9a8b1c2d3e`

### 4. Testing Viewer Access Gate
1. Open the copied private video link in an **Incognito / Private Window**.
2. You will be prompted with the **Private Video Access Gate**:
   - If you enter an ungranted email (e.g., `stranger@test.com`), access will be **Denied**.
   - If you enter a granted email (e.g., `john@example.com`), access will be **Granted** and saved in your viewing session.

### 5. Video Playback & Real-Time Tracking
1. Play, pause, seek, and finish the video.
2. Return to the **Admin Portal** &rarr; **Analytics** for that video.
3. You will see the exact session history, total watch time, watch percentage, and step-by-step event timeline!

---

## Security Highlights
- **Prepared Statements**: All database operations use PDO prepared statements to protect against SQL Injection.
- **Protected File Access**: Direct raw MP4 paths are protected; video streams are served exclusively through `video-stream.php` with session validation.
- **HTTP Range Support**: Supports byte-range requests (`HTTP 206 Partial Content`) for optimal HTML5 seeking.
- **Input Sanitization**: Cross-Site Scripting (XSS) prevention on all user inputs.
