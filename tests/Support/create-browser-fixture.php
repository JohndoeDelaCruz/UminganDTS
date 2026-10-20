<?php

// Explicit manual QA helper. This never connects to the application database.
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
$database = config('database.connections.'.config('database.default').'.database');
if (! $app->environment('testing') || ! str_ends_with((string) $database, '_testing')) {
    throw new RuntimeException('Browser fixtures require the isolated testing database.');
}
if (User::exists()) {
    throw new RuntimeException('The disposable database must be empty before browser QA. Run the automated tests first.');
}
$password = bin2hex(random_bytes(16)).'Aa!';
$admin = User::factory()->create(['name' => 'Browser QA Administrator', 'email' => 'browser-qa@example.test', 'password' => $password]);
if (! is_dir(__DIR__.'/../../.runtime')) {
    mkdir(__DIR__.'/../../.runtime', 0700, true);
}
file_put_contents(__DIR__.'/../../.runtime/browser-login.json', json_encode(['email' => $admin->email, 'password' => $password]));
echo "Created one temporary administrator in the isolated testing database. No municipal records created.\n";
