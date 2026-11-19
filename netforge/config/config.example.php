<?php
// Copy to config.php and fill in. config.php is not committed.
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'netforge',
        'user'     => 'root',
        'password' => '',
    ],
    'app' => [
        'debug' => true,
        // PHP and MySQL must agree on the time for attacks, weekly raids and review days.
        // The database connection follows this zone automatically (see Db::pdo).
        'timezone' => 'Asia/Kuala_Lumpur',
        'session_name' => 'netforge_sid',
        'idle_minutes' => 120,          // sign out after this long without a request (FR-1.2)
        'base_url' => '',               // e.g. https://netforge.example.edu; blank = work it out from the request
    ],
    'mail' => [
        'transport' => 'log',           // 'log' saves emails to storage/mail/; 'mail' sends with PHP mail() (set SMTP in php.ini)
        'from' => 'NetForge <no-reply@netforge.local>',
    ],
];
