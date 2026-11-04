<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Support\FileStore\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class SetupController extends Controller
{
    public function show(Request $request) {
        if (User::exists()) return redirect('/admin/login');
        $token = bin2hex(random_bytes(32));
        return response()->view('setup', ['token'=>$token, 'error'=>null])->cookie('cloud_setup', $token, 30, '/', null, $request->isSecure(), true, false, 'strict')->header('Cache-Control','no-store')->header('X-Frame-Options','DENY');
    }
    public function create(Request $request) {
        if (User::exists()) return redirect('/admin/login');
        $token = (string)$request->cookie('cloud_setup');
        if (strlen($token)!==64 || !hash_equals($token,(string)$request->input('_setup_token'))) abort(419, '页面已过期，请刷新重试');
        $validator = validator($request->all(), ['email'=>'required|email|max:64','password'=>'required|string|min:12|confirmed']);
        if ($validator->fails()) return response()->view('setup',['token'=>$token,'error'=>'请输入有效邮箱，密码至少 12 位，且两次输入一致。'],422)->header('Cache-Control','no-store');
        Store::transaction(function () use ($request) {
            // The lock prevents two first visitors from creating competing administrators.
            if (User::exists()) abort(409, '初始化已完成，请进入登录页');
            $path = app()->environmentFilePath();
            $environment = is_file($path) ? file_get_contents($path) : file_get_contents(base_path('.env.example'));
            if (!preg_match('/^APP_KEY=.+$/m', $environment)) {
                $key = 'APP_KEY=base64:' . base64_encode(random_bytes(32));
                $environment = preg_match('/^APP_KEY=.*$/m',$environment) ? preg_replace('/^APP_KEY=.*$/m',$key,$environment) : $environment."\n".$key."\n";
            }
            $url = $request->getSchemeAndHttpHost();
            if (preg_match('/[\s"\\\\]/',$url)) abort(422,'域名格式错误');
            $environment = preg_replace('/^APP_URL=.*$/m','APP_URL='.$url,$environment);
            $temporary = tempnam(dirname($path),'.setup-');
            if (!$temporary) throw new \RuntimeException('网站目录不可写');
            try {
                chmod($temporary,0600);
                if (file_put_contents($temporary,$environment)!==strlen($environment) || !rename($temporary,$path)) throw new \RuntimeException('无法保存网站配置');
            } finally { if (is_file($temporary)) unlink($temporary); }
            app(\App\Support\FileStore\Initialize::class)->run();
            User::create(['email'=>$request->input('email'),'password'=>password_hash($request->input('password'),PASSWORD_DEFAULT),'is_admin'=>true,'uuid'=>(string)Str::uuid(),'token'=>Str::random(32)]);
        });
        return redirect('/admin/login')->withoutCookie('cloud_setup')->header('Cache-Control','no-store');
    }
}
