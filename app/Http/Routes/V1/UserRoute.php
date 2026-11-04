<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class UserRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'user',
            'middleware' => 'admin'
        ], function ($router) {
            // User
            $router->get ('/info', 'LocalAuthController@info');
            $router->post('/changePassword', 'LocalAuthController@changePassword');
            $router->get ('/checkLogin', 'LocalAuthController@checkLogin');
            $router->get ('/getActiveSession', 'LocalAuthController@sessions');
            $router->post('/removeActiveSession', 'LocalAuthController@removeSession');
            // Order
            // Plan
            // Invite
            // Notice
            // Ticket
            // Server
            // Coupon
            // Telegram
            // Comm
            // Knowledge
            // Stat
        });
    }
}
