<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class ResetStaffPassword extends Command
{
    protected $signature = 'umdts:reset-password';

    protected $description = 'Operator recovery: securely reset an existing account password and invalidate sessions';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->ask('Existing account email')));
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error('Account not found.');

            return self::FAILURE;
        }
        $data = ['password' => $this->secret('New password'), 'password_confirmation' => $this->secret('Confirm password')];
        $validator = Validator::make($data, ['password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            AuditLog::record(null, 'operator.password_reset', $user);
        });
        $this->info('Password reset and database sessions revoked.');

        return self::SUCCESS;
    }
}
