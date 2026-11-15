<?php
// Copy to lander.config.php and adjust (install.php writes it for you). Keep it outside public/.
return [
    'pod'            => __DIR__ . '/data/content.pod',   // the SQL file (SQLite, Orbiter format)
    'data_dir'       => __DIR__ . '/data',               // rate-limit files, backups, image cache
    'media_dir'      => __DIR__ . '/public/media',       // uploads as files
    'media_url'      => '/media',                        // public path (or full URL); in a subfolder e.g. /lander/media
    'base_path'      => '',                              // '' for a domain root, '/lander' for a subfolder (then run: php bin/lander rebase /lander)
    'max_upload_mb'  => 12,
    'max_restore_mb' => 100,                             // restoring a backup (PHP upload_max_filesize / post_max_size apply as well)
    'auto_backup_days' => 7,                             // automatic backup after an admin login; 0 = off
    'auto_backup_keep' => 8,
    // Form intake (POST /api/form/{id}, server-to-server only, header X-Lander-Token). Empty = off.
    // Generate: php -r 'echo bin2hex(random_bytes(24)), "\n";'
    'form_token'     => '',
    // Sites that may read /api/public/... from the browser (CORS)
    'public_origins' => ['https://example.at', 'http://127.0.0.1:4321', 'http://localhost:4321'],
];
