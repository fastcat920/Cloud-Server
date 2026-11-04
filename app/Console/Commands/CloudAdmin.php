<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Str;
class CloudAdmin extends Command {
    protected $signature = 'cloud:admin {email}';
    protected $description = '创建或重置独立管理员';
    public function handle() {
        app(\App\Support\FileStore\Initialize::class)->run();
        $email = $this->argument('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $this->error('邮箱格式错误'); return 1; }
        $password = $this->secret('管理员密码（至少 12 位）');
        if (strlen((string)$password) < 12) { $this->error('密码至少 12 位'); return 1; }
        $user = User::firstOrNew(['email' => $email]);
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->password_algo = null; $user->password_salt = null;
        $user->is_admin = 1;
        if (!$user->exists) { $user->uuid = (string) Str::uuid(); $user->token = Str::random(32); }
        $user->save(); (new \App\Services\AuthService($user))->removeAllSession(); $this->info('管理员已保存'); return 0;
    }
}
