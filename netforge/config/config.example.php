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
    ],
];
