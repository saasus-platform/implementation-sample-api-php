<?php

namespace App\Http\Controllers;

use AntiPatternInc\Saasus\Sdk\Auth\Model\CreateSaasUserParam;
use AntiPatternInc\Saasus\Sdk\Auth\Model\CreateTenantUserParam;
use AntiPatternInc\Saasus\Sdk\Auth\Model\CreateTenantUserRolesParam;
use App\Models\DeleteUserLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class IndexController extends Controller
{
    private $client;

    public function __construct()
    {
        $this->client = new \AntiPatternInc\Saasus\Api\Client();
    }

    public function refresh(Request $request)
    {
        // リフレッシュトークンを取得
        $refreshToken = $request->cookie('SaaSusRefreshToken');
        if (!is_string($refreshToken)) {
            return response('Refresh token not found', Response::HTTP_BAD_REQUEST);
        }

        try {
            $authClient = $this->client->getAuthClient();
            $response = $authClient->getAuthCredentials([
                '',
                'refreshTokenAuth',
                $refreshToken
            ]);

            return response()->json($response->getBody());
        } catch (\Exception $e) {
            return response('Error occurred', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function userinfo(Request $request)
    {
        return response()->json($request->userinfo);
    }

    public function users(Request $request)
    {
        $tenants = $request->userinfo['tenants'];
        if (!$tenants) {
            return response()->json(['error' => 'No tenants found for the user'], Response::HTTP_BAD_REQUEST);
        }

        $tenantId = $request->query('tenant_id');
        if (!$tenantId) {
            return response()->json(['error' => 'TenantId not found'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_string($tenantId)) {
            return response()->json(['detail' => 'Invalid tenant ID'], Response::HTTP_BAD_REQUEST);
        }

        $authClient = $this->client->getAuthClient();
        $response = $authClient->getTenantUsers($tenantId);
        Log::info(json_encode($response));
        $users = [];
        foreach ($response->getUsers() as $key => $value) {
            Log::info(json_encode($value->getId()));
            $users[$key]['id'] = $value->getId();
            $users[$key]['tenant_id'] = $value->getTenantId();
            $users[$key]['email'] = $value->getEmail();
            $users[$key]['attributes'] = $value->getAttributes();

            Log::info(json_encode($value->getAttributes()));
        }

        Log::info(json_encode($users));


        return response()->json($users);
    }

    public function tenantAttributes(Request $request)
    {
        $tenants = $request->userinfo['tenants'];
        if (!$tenants) {
            return response()->json(['error' => 'No tenants found for the user'], Response::HTTP_BAD_REQUEST);
        }
    
        $tenantId = $request->query('tenant_id');
        if (!$tenantId) {
            return response()->json(['error' => 'TenantId not found'], Response::HTTP_BAD_REQUEST);
        }
    
        if (!is_string($tenantId)) {
            return response()->json(['detail' => 'Invalid tenant ID'], Response::HTTP_BAD_REQUEST);
        }
    
        try {
            $authClient = $this->client->getAuthClient();
            $tenantAttributes = $authClient->getTenantAttributes();
            $tenantInfo = $authClient->getTenant($tenantId);
    
            $result = [];
            foreach ($tenantAttributes->getTenantAttributes() as $tenantAttribute) {
                $attributeName = $tenantAttribute->getAttributeName();
                $result[$attributeName] = [
                    'display_name' => $tenantAttribute->getDisplayName(),
                    'attribute_type' => $tenantAttribute->getAttributeType(),
                    'value' => $tenantInfo->getAttributes()[$attributeName] ?? null,
                ];
            }

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json(['detail' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function userAttributes()
    {
        try {
            $authClient = $this->client->getAuthClient();
            $res = $authClient->getUserAttributes();
            $attributes = array_map(function ($attribute) {
                return [
                    'attribute_name' => $attribute->getAttributeName(),
                    'attribute_type' => $attribute->getAttributeType(),
                    'display_name'   => $attribute->getDisplayName(),
                ];
            }, $res->getUserAttributes());
    
            $response = [
                'user_attributes' => $attributes
            ];

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json(['detail' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function userRegister(Request $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');
        $tenantId = $request->input('tenantId');
        $userAttributeValues = $request->input('userAttributeValues', []);
        if (!$email || !$password || !$tenantId) {
            return response()->json(['message' => 'Missing required fields'], Response::HTTP_BAD_REQUEST);
        }

        $userInfo = $request->userinfo;
        if (!$userInfo) {
            return response()->json(['detail' => 'No user'], Response::HTTP_BAD_REQUEST);
        }

        if (!$userInfo['tenants']) {
            return response()->json(['detail' => 'No tenants found for the user'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->belongingTenant($userInfo['tenants'], $tenantId)) {
            return response()->json(['detail' => 'Tenant that does not belong'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // ユーザー属性情報を取得
            $authClient = $this->client->getAuthClient();
            $userAttributesResponse = $authClient->getUserAttributes();
            $userAttributes = $userAttributesResponse->getUserAttributes();
            foreach ($userAttributes as $attribute) {
                $attributeName = $attribute->getAttributeName();
                $attributeType = $attribute->getAttributeType();

                if (isset($userAttributeValues[$attributeName]) && $attributeType === 'number') {
                    $userAttributeValues[$attributeName] = (int) $userAttributeValues[$attributeName];
                }
            }

            // SaaSユーザー登録用パラメータを作成
            $createSaasUserParam = new CreateSaasUserParam();
            $createSaasUserParam
                ->setEmail($email)
                ->setPassword($password);

            // SaaSユーザーを登録
            $authClient->createSaasUser($createSaasUserParam);

            // テナントユーザー登録用のパラメータを作成
            $createTenantUserParam = new CreateTenantUserParam();
            $createTenantUserParam
                ->setEmail($email)
                ->setAttributes($userAttributeValues);

            // 作成したSaaSユーザーをテナントユーザーに追加
            $tenantUser = $authClient->createTenantUser($tenantId, $createTenantUserParam);

            // テナントに定義されたロール一覧を取得
            $roleObj = $authClient->getRoles();

            // 初期値はadmin（SaaS管理者）とする
            $addRole = collect($roleObj)->contains('role_name', 'user') ? 'user' : 'admin';

            // ロール設定用のパラメータを作成
            $createTenantUserRolesParam = new CreateTenantUserRolesParam();
            $createTenantUserRolesParam
                ->setRoleNames([$addRole]);

            // 作成したテナントユーザーにロールを設定
            $authClient->createTenantUserRoles($tenantId, $tenantUser->getId(), 3, $createTenantUserRolesParam);

            return response()->json(['message' => 'User registered successfully']);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json(['detail' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function userDelete(Request $request)
    {
        $tenantId = $request->input('tenantId');
        $userId = $request->input('userId');
        if (!$tenantId || !$userId) {
            return response()->json(['message' => 'Missing required fields'], Response::HTTP_BAD_REQUEST);
        }

        $userInfo = $request->userinfo;
        if (!$userInfo) {
            return response()->json(['detail' => 'No user'], Response::HTTP_BAD_REQUEST);
        }

        if (!$userInfo['tenants']) {
            return response()->json(['detail' => 'No tenants found for the user'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->belongingTenant($userInfo['tenants'], $tenantId)) {
            return response()->json(['detail' => 'Tenant that does not belong'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // SaaSusからユーザー情報を取得
            $authClient = $this->client->getAuthClient();
            $deleteUser = $authClient->getTenantUser($tenantId, $userId);

            // テナントからユーザー情報を削除
            $authClient->deleteTenantUser($tenantId, $userId);

            // ユーザー削除ログを設定
            $deleteUserLog = new DeleteUserLog();
            $deleteUserLog->tenant_id = $tenantId;
            $deleteUserLog->user_id = $userId;
            $deleteUserLog->email = $deleteUser->getEmail();
            $deleteUserLog->save();

            return response()->json(['message' => 'User deleted successfully']);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json(['detail' => 'Error occurred during user deletion'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function deleteUserLog(Request $request)
    {
        $tenantId = $request->query('tenant_id');
        if (!$tenantId || !is_string($tenantId)) {
            return response()->json(['detail' => $tenantId ? 'Invalid tenant ID' : 'No tenant'], Response::HTTP_BAD_REQUEST);
        }

        $userInfo = $request->userinfo;
        if (!$userInfo) {
            return response()->json(['detail' => 'No user'], Response::HTTP_BAD_REQUEST);
        }

        if (!$userInfo['tenants']) {
            return response()->json(['detail' => 'No tenants found for the user'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->belongingTenant($userInfo['tenants'], $tenantId)) {
            return response()->json(['detail' => 'Tenant that does not belong'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // ユーザー削除ログを取得
            $logs = DeleteUserLog::where('tenant_id', $tenantId)->get();

            $responseData = $logs->map(function ($log) {
                return [
                    'id' => $log->id,
                    'tenant_id' => $log->tenant_id,
                    'user_id' => $log->user_id,
                    'email' => $log->email,
                    'delete_at' => $log->delete_at,
                ];
            });

            return response()->json($responseData);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json(['detail' => 'Error occurred while retrieving logs'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function pricingPlan(Request $request)
    {
        $userInfo = $request->userinfo;
        if (!$userInfo) {
            return response()->json(['detail' => 'No user'], Response::HTTP_BAD_REQUEST);
        }

        if (!$userInfo['tenants']) {
            return response()->json(['detail' => 'No tenants found for the user'], Response::HTTP_BAD_REQUEST);
        }

        $planId = $request->query('plan_id');
        if (!$planId || !is_string($planId)) {
            return response()->json(['detail' => $planId ? 'Invalid tenant ID' : 'No price plan found for the tenant'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $pricingClient = $this->client->getPricingClient();

            $plan = $pricingClient->getPricingPlan($planId);

            $result = [
                'display_name' => $plan->getDisplayName()
            ];

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json(['detail' => 'Error occurred while retrieving pricing plan'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function tenantAttributesList(Request $request)
    {
        $userInfo = $request->userinfo;
        if (!$userInfo) {
            return response()->json(['detail' => 'No user'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $authClient = $this->client->getAuthClient();
            $res = $authClient->getTenantAttributes();
            $attributes = array_map(function ($attribute) {
                return [
                    'attribute_name' => $attribute->getAttributeName(),
                    'attribute_type' => $attribute->getAttributeType(),
                    'display_name'   => $attribute->getDisplayName(),
                ];
            }, $res->getTenantAttributes());
    
            $response = [
                'tenant_attributes' => $attributes
            ];

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json(['detail' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function selfSignUp(Request $request)
    {
        $tenantName = $request->input('tenantName');
        $tenantAttributeValues = $request->input('tenantAttributeValues', []);
        $userAttributeValues = $request->input('userAttributeValues', []);
        if (!$tenantName) {
            return response()->json(['message' => 'Missing required fields'], Response::HTTP_BAD_REQUEST);
        }

        $userInfo = $request->userinfo;
        if (!$userInfo) {
            return response()->json(['detail' => 'No user'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $authClient = $this->client->getAuthClient();

            // テナント属性情報の取得
            $tenantAttributesResponse = $authClient->getTenantAttributes();
            $tenantAttributes = $tenantAttributesResponse->getTenantAttributes();
            foreach ($tenantAttributes as $attribute) {
                $attributeName = $attribute->getAttributeName();
                $attributeType = $attribute->getAttributeType();

                // テナント属性情報で number 型が定義されている場合は置換する
                if (isset($tenantAttributeValues[$attributeName]) && $attributeType === 'number') {
                    $tenantAttributeValues[$attributeName] = (int) $tenantAttributeValues[$attributeName];
                }
            }

            $email = $userInfo['email'];

            // テナントを作成
            $requestBody = new \stdClass();
            $requestBody->name = $tenantName;
            $requestBody->attributes = $tenantAttributeValues;
            $requestBody->back_office_staff_email = $email;
            $createdTenant = $authClient->createTenant($requestBody);

            // 作成したテナントのIDを取得
            $tenantId = $createdTenant->getId();

            // ユーザー属性情報を取得
            $userAttributesResponse = $authClient->getUserAttributes();
            $userAttributes = $userAttributesResponse->getUserAttributes();
            foreach ($userAttributes as $attribute) {
                $attributeName = $attribute->getAttributeName();
                $attributeType = $attribute->getAttributeType();

                // ユーザー属性情報で number 型が定義されている場合は置換する
                if (isset($userAttributeValues[$attributeName]) && $attributeType === 'number') {
                    $userAttributeValues[$attributeName] = (int) $userAttributeValues[$attributeName];
                }
            }

            // テナントユーザー登録用のパラメータを作成
            $createTenantUserParam = new CreateTenantUserParam();
            $createTenantUserParam
                ->setEmail($email)
                ->setAttributes($userAttributeValues);

            // SaaSユーザーをテナントユーザーに追加
            $tenantUser = $authClient->createTenantUser($tenantId, $createTenantUserParam);

            // ロール設定用のパラメータを作成
            $createTenantUserRolesParam = new CreateTenantUserRolesParam();
            $createTenantUserRolesParam
                ->setRoleNames(['admin']);

            // 作成したテナントユーザーにロールを設定
            $authClient->createTenantUserRoles($tenantId, $tenantUser->getId(), 3, $createTenantUserRolesParam);

            return response()->json(['message' => 'User registered successfully']);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            dump($e->getMessage());
            return response()->json(['detail' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function logout(Request $request) {
        return response()->json([
            'message' => 'Logged out successfully'
        ])->withCookie(cookie()->forget('SaaSusRefreshToken'));
    }

    private function belongingTenant(array $tenants, string $tenantId): bool
    {
        return collect($tenants)->contains('id', $tenantId);
    }
}
