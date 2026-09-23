<?php
// config.php — keep this file private (never put it in a public web folder on a live server)

define('OD_BASE_URL', 'https://api.opendental.com/api/v1/');

// Developer Key: Developer Portal > Account
define('OD_DEVELOPER_KEY', '5MINUkGjsqKg5MiJ');

// Customer Key: Developer Portal > Customer Keys (the Arvada Implants key)
define('OD_CUSTOMER_KEY', 'pdoxOoT8CcsGi4Kd');

define('OD_CA_FILE', '');


// ---------------- GoHighLevel ----------------
// Private Integration token (starts with "pit-"): Settings > Private Integrations
define('GHL_TOKEN', 'pit-f8b95c0d-5deb-4662-a445-0968b09e9cea');
 
// Sub-account (location) ID: the part after /location/ in your GHL address bar
define('GHL_LOCATION_ID', 'VuqwtrNXGZ2nRYITMjn6');
 
// Calendar that Open Dental appointments go into.
// Open sync.html and click "List GHL calendars" to find the ID.
define('GHL_CALENDAR_ID', 'IVGLhL7dm29tjfiXuCHw');
 
// The practice's time zone. Open Dental times are local practice time.
// Arvada, Colorado = America/Denver
define('PRACTICE_TIMEZONE', 'America/Denver');
 
// Password for sync.php. Make up a long random string and type it into sync.html.
define('SYNC_SECRET', 'b71b6f25d21cf5b24d034bdf28d56d4cac05d188');
 
 