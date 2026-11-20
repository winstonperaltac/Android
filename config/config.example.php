<?php
// Copie este archivo como config.php (o use el instalador web: /install/)
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'prestamos',
        'user' => 'usuario_bd',
        'pass' => 'clave_bd',
    ],
    'timezone' => 'America/Santo_Domingo',
    'session_timeout' => 3600, // segundos de inactividad antes de cerrar sesión
    'debug' => false,
];
