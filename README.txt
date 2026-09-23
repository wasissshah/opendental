ADMIN PORTAL - INSTALL ON HOSTINGER
===================================

1. DATABASE
   hPanel > Databases > MySQL Databases: create a database + user (note name, user, password).
   hPanel > Databases > phpMyAdmin > open that database > Import > choose database.sql > Go.

2. FILES
   Upload everything in this zip to your site folder (public_html of opendental.speedadsai.com),
   replacing the old files. Delete the old index.html and sync.html.
   Keep your old data/sync_map.json: move it to data/loc_1/sync_map.json after step 5
   (so the first location keeps its links and GHL gets no duplicates).

3. EMAIL (for login codes)
   hPanel > Emails: create a mailbox, e.g. no-reply@speedadsai.com.

4. CONFIG
   Edit config.php: DB_NAME, DB_USER, DB_PASS, SMTP_USER, SMTP_PASS, MAIL_FROM, APP_URL.
   Open https://your-site/setup.php - it shows a random APP_KEY. Paste it into config.php.
   Never change APP_KEY afterwards.

5. FIRST ADMIN
   Refresh setup.php: all checks green, then create your admin (name + email).
   Log in at https://your-site/login.php with that email.

6. LOCATIONS
   Locations > Add Location > fill GHL and Open Dental settings > Test Connection.
   Then "Sync to GHL" > Automatic sync > Switch on (this replaces the old subscriptions).
