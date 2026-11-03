<?php

// Router for PHP's built-in server when it is started from outside the portal folder
// (equivalent to what `php artisan serve` does). Not used on WAMP or in production.
chdir(__DIR__.'/public');

return require __DIR__.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
