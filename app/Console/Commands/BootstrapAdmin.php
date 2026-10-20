<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class BootstrapAdmin extends Command
{
    protected $signature = 'umdts:bootstrap-admin';

    protected $description = 'Interactively provision the first real administrator; never creates default credentials';

    public function handle(): int
    {
        if (User::where('role', 'admin')->exists()) {
            $this->error('An administrator already exists. Use account management or operator password recovery.');

            return self::FAILURE;
        }
        $data = [
            'name' => $this->ask('Administrator full name'),
            'email' => strtolower(trim((string) $this->ask('Administrator email'))),
            'password' => $this->secret('Password (at least 12 characters, mixed case, a number and a symbol)'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        if ($mysql && (int) DB::selectOne("SELECT GET_LOCK('umdts_first_admin', 10) AS acquired")->acquired !== 1) {
            $this->error('Another setup is running. Retry after it completes.');

            return self::FAILURE;
        }
        try {
            $created = DB::transaction(function () use ($data) {
                if (User::where('role', 'admin')->exists()) {
                    return false;
                }
                $user = User::create([
                    'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
                    'role' => 'admin', 'is_active' => true, 'department_id' => null,
                ]);
                AuditLog::record($user, 'admin.bootstrapped', $user);

                return true;
            });
        } finally {
            if ($mysql) {
                DB::select("SELECT RELEASE_LOCK('umdts_first_admin')");
            }
        }
        if (! $created) {
            $this->error('An administrator was provisioned by another setup. No account was created.');

            return self::FAILURE;
        }
        $this->info('Administrator created. Sign in, create your real departments and categories, then assign your office.');

        return self::SUCCESS;
    }
}
