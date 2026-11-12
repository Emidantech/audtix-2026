<?php
// Copy this file to  api/config.php  (or, better, to  audtix-config.php  one folder ABOVE public_html)
// and fill in your own values. The real config file is never committed to Git.
return [
    // MySQL database created in hPanel > Databases
    'db_host' => 'localhost',
    'db_name' => 'u123456789_audtix',
    'db_user' => 'u123456789_audtix',
    'db_pass' => 'CHANGE-ME',

    // Where the notification copy goes
    'notify_to'   => 'info@audtix.com',
    'notify_cc'   => 'audtixsystem@gmail.com',

    // Email sending: use a mailbox you created in hPanel > Emails (for example no-reply@audtix.com).
    // smtp_secure: 'ssl' (port 465) or 'tls' (port 587). Leave smtp_host empty to use PHP mail() instead.
    'mail_from'      => 'no-reply@audtix.com',
    'mail_from_name' => 'Audtix Website',
    'smtp_host'   => 'smtp.hostinger.com',
    'smtp_port'   => 465,
    'smtp_secure' => 'ssl',
    'smtp_user'   => 'no-reply@audtix.com',
    'smtp_pass'   => 'CHANGE-ME',

    // Uploaded documents. Best: a folder above public_html. Leave empty to use ../audtix-storage next to public_html.
    'storage_dir' => '',

    // One-time secret that unlocks /admin/setup.php to create the first super admin.
    // Pick a long random value, use it once, then delete it from this file.
    'setup_token' => '',

    // Only set to true while debugging.
    'debug' => false,
];
