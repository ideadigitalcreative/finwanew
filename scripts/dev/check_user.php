<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Clear cache
\Illuminate\Support\Facades\Cache::flush();

$user = App\Models\User::where('email', 'haerulhadi00@gmail.com')->first();

if ($user) {
    // Force fresh from database
    $user->refresh();

    echo 'User ID: '.$user->id.PHP_EOL;
    echo 'Name: '.$user->name.PHP_EOL;
    echo 'Email: '.$user->email.PHP_EOL;
    echo 'Avatar: '.($user->avatar ?? 'NULL').PHP_EOL;
    echo 'Updated At: '.$user->updated_at.PHP_EOL;
} else {
    echo 'User not found'.PHP_EOL;
}
