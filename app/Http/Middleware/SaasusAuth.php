<?php

namespace App\Http\Middleware;

use AntiPatternInc\Saasus\Api\Client as ApiClient;
use Closure;
use Http\Client\Exception\HttpException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SaasusAuth
{
    // userinfo レスポンスのキャッシュ有効期間（秒）
    // この期間中はロール変更・ユーザー無効化が反映されないため、
    // セキュリティ要件に応じて調整すること
    private const CACHE_TTL_SECONDS = 60;

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        if (empty($token)) {
            if (isset($_COOKIE['SaaSus_idToken'])) {
                $token = $_COOKIE['SaaSus_idToken'];
            } else {
                Log::info('Can not get SaaSus ID token.');
                if (getenv('SAASUS_AUTH_MODE') == 'api') {
                    return response()->json('Invalid ID Token.', Response::HTTP_UNAUTHORIZED);
                } else {
                    return redirect(getenv('SAASUS_LOGIN_URL'));
                }
            }
        }

        $referer = $request->headers->get('referer', '');
        $xSaasusReferer = $request->headers->get('x-saasus-referer', '');

        // referer もキーに含めることで、同一トークンでも referer が異なる場合に
        // 別エントリとしてキャッシュする
        $cacheKey = 'saasus_userinfo:' . hash('sha256', $token . ':' . $referer);

        $cacheHit = Cache::has($cacheKey);
        Log::info('[SaasusAuth] cache ' . ($cacheHit ? 'HIT' : 'MISS') . ' key=' . substr($cacheKey, 0, 40));
        $startTime = microtime(true);

        try {
            $userinfo = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($token, $referer, $xSaasusReferer, $cacheKey) {
                $client = new ApiClient($referer, $xSaasusReferer);
                $authApiClient = $client->getAuthClient();
                $response = $authApiClient->getUserInfo(['token' => $token], $authApiClient::FETCH_RESPONSE);
                return json_decode($response->getBody(), true);
            });
            Log::info('[SaasusAuth] elapsed=' . round((microtime(true) - $startTime) * 1000) . 'ms');
        } catch (\Exception $e) {
            if ($e instanceof HttpException) {
                $statusCode = $e->getResponse()->getStatusCode();
                $body = json_decode($e->getResponse()->getBody(), true);
                $type = $body['type'] ?? 'unknown';
                $message = $body['message'] ?? 'unknown error';

                if ($statusCode == Response::HTTP_UNAUTHORIZED) {
                    // 認証エラーの場合はキャッシュを削除してから返す
                    Cache::forget($cacheKey);
                    Log::info('Type: ' . $type . ', Message: ' . $message);
                    if (getenv('SAASUS_AUTH_MODE') == 'api') {
                        return response()->json(['type' => $type, 'message' => $message], Response::HTTP_UNAUTHORIZED);
                    } else {
                        return redirect(getenv('SAASUS_LOGIN_URL'));
                    }
                }

                Log::info('Type: ' . $type . ', Message: ' . $message);
                return response()->json(['type' => $type, 'message' => $message], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            Log::info('Uncaught error: ' . $e);
            return response()->json('Uncaught error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $request->merge(['userinfo' => $userinfo]);

        return $next($request);
    }
}
