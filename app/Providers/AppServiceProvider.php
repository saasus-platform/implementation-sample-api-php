<?php

namespace App\Providers;

use AntiPatternInc\Saasus\Api\Client as SaasusClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // ApiClient をシングルトン登録することで、同一ワーカープロセス内で
        // GuzzleClient（TCP接続プール）が再利用される
        // ※ referer/xSaasusReferer は各コントローラーでは使用しないため空文字で固定
        $this->app->singleton(SaasusClient::class, function () {
            return new SaasusClient();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
