<?php
// Kopieren nach lander.config.php und anpassen. Liegt außerhalb von public/.
return [
    'pod'            => __DIR__ . '/data/content.pod',   // SQLite, Orbiter-Format
    'data_dir'       => __DIR__ . '/data',               // Rate-Limit-Dateien
    'media_dir'      => __DIR__ . '/public/media',       // Uploads als Dateien
    'media_url'      => '/media',                        // öffentlicher Pfad (oder volle URL)
    'max_upload_mb'  => 12,
    'max_restore_mb' => 100,                         // Backup-Wiederherstellung (zusätzlich gelten PHP upload_max_filesize / post_max_size)
    // Formular-Annahme (POST /api/form/{id}, nur Server-zu-Server, Header X-Lander-Token). Leer = aus.
    // Erzeugen: php -r 'echo bin2hex(random_bytes(24)), "\n";'  — derselbe Wert steht in anfrage.config.php der Website.
    'form_token'     => '',
    // Websites, die /api/public/... im Browser lesen dürfen (CORS)
    'public_origins' => ['https://psychiaterin-graz.at', 'http://127.0.0.1:4321', 'http://localhost:4321'],
];
