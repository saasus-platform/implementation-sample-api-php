<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\IndexController;
use AntiPatternInc\Saasus\Laravel\Controllers\CallbackApiController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// 一時コードからIDトークンなどの認証情報を取得するコントローラを登録
Route::get('/credentials', [CallbackApiController::class, 'index']);
Route::get('/refresh', [IndexController::class, 'refresh']);

// SaaSus SDK標準のAuth Middlewareを利用する
Route::middleware(\AntiPatternInc\Saasus\Laravel\Middleware\Auth::class)->group(function () {
    Route::get('/userinfo', [IndexController::class, 'userinfo']);
    Route::get('/users', [IndexController::class, 'users']);
    Route::get('/tenant_attributes', [IndexController::class, 'tenantAttributes']);
    Route::get('/user_attributes', [IndexController::class, 'userAttributes']);
    Route::post('/user_register', [IndexController::class, 'userRegister']);
    Route::delete('/user_delete', [IndexController::class, 'userDelete']);
    Route::get('/delete_user_log', [IndexController::class, 'deleteUserLog']);
    Route::get('/pricing_plan', [IndexController::class, 'pricingPlan']);
    Route::get('/tenant_attributes_list', [IndexController::class, 'tenantAttributesList']);
    Route::post('/self_sign_up', [IndexController::class, 'selfSignUp']);
    Route::post('/logout', [IndexController::class, 'logout']);
});
