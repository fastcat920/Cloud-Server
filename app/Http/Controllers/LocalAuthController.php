<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
class LocalAuthController extends Controller
{
    public function login(Request $request) {
        $data = $request->validate(['email'=>'required|email', 'password'=>'required|string']);
        $key = 'local-login:' . hash('sha256', strtolower($data['email']) . '|' . $request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) abort(429, '尝试过于频繁，请一分钟后重试');
        RateLimiter::hit($key, 60);
        $user = User::where('email', $data['email'])->first();
        if (!$user || !$user->is_admin || !password_verify($data['password'], $user->password)) abort(422, '邮箱或密码错误');
        RateLimiter::clear($key);
        return response(['data'=>(new AuthService($user))->generateAuthData($request)]);
    }
    public function info(Request $request) { return response(['data'=>['id'=>$request->user['id'], 'email'=>$request->user['email'], 'is_admin'=>true]]); }
    public function checkLogin() { return response(['data'=>['is_login'=>true, 'is_admin'=>true]]); }
    public function changePassword(Request $request) {
        $data = $request->validate(['old_password'=>'required|string','new_password'=>'required|string|min:12']);
        $user = User::findOrFail($request->user['id']);
        if (!password_verify($data['old_password'], $user->password)) abort(422, '原密码错误');
        $user->password = password_hash($data['new_password'], PASSWORD_DEFAULT); $user->save();
        (new AuthService($user))->removeAllSession();
        return response(['data'=>true]);
    }
    public function sessions(Request $request) { return response(['data'=>(new AuthService(User::findOrFail($request->user['id'])))->getSessions()]); }
    public function removeSession(Request $request) {
        $data = $request->validate(['session_id'=>'required|string']);
        return response(['data'=>(new AuthService(User::findOrFail($request->user['id'])))->removeSession($data['session_id'])]);
    }
}
