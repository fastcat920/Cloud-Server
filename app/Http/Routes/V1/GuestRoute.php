<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class GuestRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'guest'
        ], function ($router) {
            // Telegram
            // Payment
            // Comm
            $router->get ('/comm/config', 'V1\\Guest\\CommController@config');
        });
    }
}
