<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
/* ── SaaSus SDK ───────────────────────────────────────────── */
use AntiPatternInc\Saasus\Api\Client  as SaasusClient;
use AntiPatternInc\Saasus\Sdk\Auth\Client  as AuthClient;
use AntiPatternInc\Saasus\Sdk\Pricing\Client  as PricingClient;
use AntiPatternInc\Saasus\Sdk\Pricing\Model\UpdateMeteringUnitTimestampCountParam;
use AntiPatternInc\Saasus\Sdk\Pricing\Model\UpdateMeteringUnitTimestampCountNowParam;
use AntiPatternInc\Saasus\Sdk\Pricing\Model\PricingPlan;

class BillingController extends Controller
{
  private AuthClient    $auth;
  private PricingClient $pricing;

  public function __construct()
  {
    $client        = new SaasusClient();
    $this->auth    = $client->getAuthClient();
    $this->pricing = $client->getPricingClient();
  }


  /* ================================================================
   * /billing/dashboard   ─ 課金ダッシュボード
   *=============================================================== */
  public function dashboard(Request $req)
  {
    try {
      $tenantId = (string)$req->query('tenant_id');
      $planId   = (string)$req->query('plan_id');
      $start    = (int)$req->query('period_start');
      $end      = (int)$req->query('period_end');

      if (
        is_null($tenantId) || $tenantId === '' ||
        is_null($planId)   || $planId === ''   ||
        is_null($start)    ||
        is_null($end)
      ) {
        return response()->json(
          ['detail' => 'tenant_id, plan_id, period_start, period_end are required'],
          Response::HTTP_BAD_REQUEST
        );
      }

      // 権限（admin / sadmin）チェック
      if (!$this->hasBillingAccess($req->userinfo ?? [], $tenantId)) {
        return response()->json(['detail' => 'Insufficient permissions'], Response::HTTP_FORBIDDEN);
      }

      /* プラン情報取得 */
      $plan = $this->pricing->getPricingPlan($planId);
      if (!$plan) {
        return response()->json(['detail' => 'Pricing plan not found for the given plan_id.'], Response::HTTP_NOT_FOUND);
      }

      /* 該当プラン履歴の tax_rate_id を取得 */
      $tenant = $this->auth->getTenant($tenantId);
      if (!$tenant) {
        return response()->json(['detail' => 'Tenant not found for the given tenant_id.'], Response::HTTP_NOT_FOUND);
      }
      // プラン履歴をコレクション化
      $edges = collect($tenant->getPlanHistories())
        ->map(fn($h) => [
          'plan_id' => $h->getPlanId() ?? '',
          'tax_id'  => $h->getTaxRateId() ?? '',
          'time'    => $h->getPlanAppliedAt(),
        ])
        ->sortBy('time')
        ->values();

      // 指定プラン・期間に該当する履歴を取得
      $matchedHistory = $edges->last(
        fn($e) =>
        $e['plan_id'] === $planId && $e['time'] <= $start
      );

      // 該当税率情報
      $matchedTax = null;
      if ($matchedHistory && $matchedHistory['tax_id']) {
        $allTaxRates = $this->pricing->getTaxRates();
        foreach ($allTaxRates->getTaxRates() as $taxRate) {
          if ($taxRate['id'] === $matchedHistory['tax_id']) {
            $matchedTax = $taxRate;
            break;
          }
        }
      }

      /* メータ課金計算 */
      [$meteringUnitBillings, $currencyTotals] =
        $this->calcMeteringUnitBillings($tenantId, $start, $end, $plan);

      $response = [
        'summary' => [
          'total_by_currency'    => $currencyTotals,
          'total_metering_units' => count($meteringUnitBillings),
        ],
        'metering_unit_billings' => $meteringUnitBillings,
        'pricing_plan_info'  => [
          'plan_id'      => $planId,
          'display_name' => $plan->getDisplayName(),
          'description'  => $plan->getDescription(),
        ],
        'tax_rate'     => $matchedTax,
      ];


      return response()->json($response);
    } catch (\Throwable $e) {
      Log::error($e->getMessage());
      return response()->json(['detail' => 'billing calculation failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /* ================================================================
   * /tenant/plan_periods   ─ プラン適用期間 選択肢
   *=============================================================== */
  /**
   * テナントのプラン期間（請求区間）を取得して返却する。
   *
   * - プラン履歴と現在のプラン終了日時から境界点を作成
   * - 年払い／月払いを判定して期間を分割
   * - 最新→過去の順で JSON を返す
   */
  public function planPeriods(Request $req)
  {
    try {
      // =========================
      // 0) 入力チェック
      // =========================
      $tenantId = (string)$req->query('tenant_id');
      if (!$tenantId) {
        // tenant_id が無い場合は 400 Bad Request
        return response()->json(['detail' => 'tenant_id required'], Response::HTTP_BAD_REQUEST);
      }

      // =========================
      // 1) テナント情報取得
      // =========================
      $tenant = $this->auth->getTenant($tenantId);
      $tz     = new \DateTimeZone('Asia/Tokyo');

      // ==============================================================
      // 2) プラン履歴（edge）を昇順で並べ替えて境界リストを作成
      //    - $edge['time'] はプラン適用開始日時
      // ==============================================================
      $edges = collect($tenant->getPlanHistories())
        ->map(fn($h) => [
          'plan_id' => $h->getPlanId() ?? '',                            // プランID（null対策）
          'time'    => (new \DateTime())                                  // 適用開始日時
            ->setTimestamp($h->getPlanAppliedAt())
            ->setTimezone($tz),
        ])
        ->sortBy('time')  // 昇順
        ->values();       // index を振り直す

      // ==============================================================
      // 3) 最後の境界点を決定
      //    - current_plan_period_end があればその1秒前
      //    - 無ければ「今」を終端にする
      // ==============================================================
      $fixedLast = $tenant->getCurrentPlanPeriodEnd()
        ? (new \DateTime())
        ->setTimestamp($tenant->getCurrentPlanPeriodEnd() - 1)
        ->setTimezone($tz)
        : (new \DateTime())->setTimezone($tz); // fallback: 現在時刻

      $results = [];

      // ==============================================================
      // 4) 境界リストを基に各プラン期間を月／年単位で分割
      // ==============================================================
      foreach ($edges as $idx => $edge) {
        $planId = $edge['plan_id'];
        if ($planId === '') {
          continue;  // プラン無しはスキップ
        }

        /* プラン情報取得 */
        $plan = $this->pricing->getPricingPlan($planId);
        if (!$plan) {
          return response()->json(['detail' => 'Pricing plan not found for the given plan_id.'], Response::HTTP_NOT_FOUND);
        }
        // 当該プランの開始時刻
        $periodStart = clone $edge['time'];

        // 当該プランの終了時刻（次の境界 or fixedLast）
        $periodEnd = $idx + 1 < count($edges)
          ? (clone $edges[$idx + 1]['time'])->modify('-1 second')
          : clone $fixedLast;

        // ------------------------------------------------------------
        // 4-1) 年払いプランか月払いプランかを判定
        // ------------------------------------------------------------
        $recurring = $this->planHasYearUnit($plan) ? 'year' : 'month';

        // ------------------------------------------------------------
        // 4-2) 期間を step（1ヶ月 or 1年）単位で分割し $results へ
        // ------------------------------------------------------------
        for ($cur = clone $periodStart; $cur <= $periodEnd;) {
          // 次の境界点を計算
          $next = clone $cur;
          $next->modify($recurring === 'year' ? '+1 year' : '+1 month');

          // step 区間の終端（1秒前）
          $end = (clone $next)->modify('-1 second');

          // 計算上の終端が正式な periodEnd を超えないよう調整
          if ($end > $periodEnd) {
            $end = clone $periodEnd;
          }

          // 1秒以上の幅が無ければ終了
          if ($end <= $cur) {
            break;
          }

          // 結果に push
          $results[] = [
            // ラベル（例: 2025年04月01日 00:00:00 ～ 2025年04月30日 23:59:59）
            'label' => sprintf(
              '%04d年%02d月%02d日 %02d:%02d:%02d ～ %04d年%02d月%02d日 %02d:%02d:%02d',
              ...array_map('intval', [
                $cur->format('Y'),
                $cur->format('m'),
                $cur->format('d'),
                $cur->format('H'),
                $cur->format('i'),
                $cur->format('s'),
                $end->format('Y'),
                $end->format('m'),
                $end->format('d'),
                $end->format('H'),
                $end->format('i'),
                $end->format('s'),
              ])
            ),
            'plan_id' => $planId,
            'start'   => $cur->getTimestamp(),
            'end'     => $end->getTimestamp(),
          ];

          // 最終区間まで処理したら抜ける
          if ($end == $periodEnd) {
            break;
          }

          // 次ループの開始時刻 (= 直前 end の 1 秒後)
          $cur = (clone $end)->modify('+1 second');
        }
      }

      // ==============================================================
      // 5) 返却用に新しい順（start のDESC）へ並び替え
      // ==============================================================
      usort($results, fn($a, $b) => $b['start'] <=> $a['start']);

      // ==============================================================
      // 6) JSON で返却
      // ==============================================================
      return response()->json($results);
    } catch (\Throwable $e) {
      Log::error($e->getMessage());
      return response()->json(['detail' => 'plan periods failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }


  /* ================================================================
   * /metering/{tenantId}/{unit}/{ts}   ─ メータ更新
   *=============================================================== */
  public function updateCountOfSpecifiedTimestamp(Request $req, string $tenantId, string $unit, int $ts)
  {
    // 権限（admin / sadmin）チェック
    if (!$this->hasBillingAccess($req->userinfo ?? [], $tenantId)) {
      return response()->json(['detail' => 'Insufficient permissions'], Response::HTTP_FORBIDDEN);
    }

    try {
      $method = $req->input('method');          // add|sub|direct
      $count  = (int)$req->input('count', 0);

      if (!in_array($method, ['add', 'sub', 'direct'], true) || $count < 0) {
        return response()->json(['detail' => 'invalid method / count'], Response::HTTP_BAD_REQUEST);
      }

      $param = (new UpdateMeteringUnitTimestampCountParam())
        ->setMethod($method)
        ->setCount($count);

      $this->pricing->updateMeteringUnitTimestampCount(
        $tenantId,
        $unit,
        $ts,
        $param
      );

      return response()->json(['ok' => true]);
    } catch (\Throwable $e) {
      Log::error($e->getMessage());
      return response()->json(['detail' => 'meter update failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /* ================================================================
   * /metering/{tenantId}/{unit}/   ─ メータ更新
   *=============================================================== */
  public function updateCountOfNow(Request $req, string $tenantId, string $unit)
  {
    // 権限（admin / sadmin）チェック
    if (!$this->hasBillingAccess($req->userinfo ?? [], $tenantId)) {
      return response()->json(['detail' => 'Insufficient permissions'], Response::HTTP_FORBIDDEN);
    }

    try {
      $method = $req->input('method');          // add|sub|direct
      $count  = (int)$req->input('count', 0);

      if (!in_array($method, ['add', 'sub', 'direct'], true) || $count < 0) {
        return response()->json(['detail' => 'invalid method / count'], Response::HTTP_BAD_REQUEST);
      }

      $param = (new UpdateMeteringUnitTimestampCountNowParam())
        ->setMethod($method)
        ->setCount($count);

      $this->pricing->updateMeteringUnitTimestampCountNow(
        $tenantId,
        $unit,
        $param
      );

      return response()->json(['ok' => true]);
    } catch (\Throwable $e) {
      Log::error($e->getMessage());
      return response()->json(['detail' => 'meter update failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /* ================================================================
   * ユーティリティ関数
   *=============================================================== */
  /** admin / sadmin 権限を持つか */
  private function hasBillingAccess(array $userinfo, string $tenantId): bool
  {
    // まずテナントに属しているかチェック
    if (!$this->belongingTenant($userinfo['tenants'] ?? [], $tenantId)) {
      return false;
    }

    foreach ($userinfo['tenants'] ?? [] as $t) {
      if ($t['id'] !== $tenantId) {
        continue;
      }
      foreach ($t['envs'] as $env) {
        foreach ($env['roles'] as $role) {
          if (in_array($role['role_name'], ['admin', 'sadmin'], true)) {
            return true;
          }
        }
      }
    }
    return false;
  }

  /** ユーザがそのテナントに属するか */
  private function belongingTenant(array $tenants, string $tenantId): bool
  {
    return collect($tenants)->contains('id', $tenantId);
  }

  /** プランに recurring_interval == 'year' の unit があるか */
  private function planHasYearUnit(PricingPlan $plan): bool
  {
    try {

      $menus = $plan->getPricingMenus();

      foreach ($menus as $menu) {
        foreach ($menu['units'] ?? [] as $unit) {
          $r = $unit['recurring_interval'] ?? 'month';
          if ($r === 'year') {
            return true;
          }
        }
      }
    } catch (\Throwable $e) {
      Log::warning('[planHasYearUnit] ' . $e->getMessage());
    }
    return false;
  }


  /**
   * Calculates the metering units for a given tenant within a specified period and plan.
   *
   * @param string $tenantId The unique identifier of the tenant.
   * @param int $start The start timestamp (UNIX epoch) of the metering period.
   * @param int $end The end timestamp (UNIX epoch) of the metering period.
   * @param PricingPlan $plan The plan information.
   * @return array The calculated metering units and related data.
   *
   * このメソッドは、指定されたテナントID、期間、プラン情報に基づいて
   * フロントエンドで利用する「計測単位の課金情報（MeteringUnitBilling）」形式の配列を生成します。
   *
   * MeteringUnitBilling 形式:
   * [
   *   {
   *     metering_unit_name: string,
   *     function_menu_name: string,
   *     period_count: number,
   *     currency: string,
   *     period_amount: number,
   *     pricing_unit_display_name: string
   *   },
   *   ...
   * ]
   */
  private function calcMeteringUnitBillings(string $tenantId, int $start, int $end, PricingPlan $plan): array
  {
    $meteringUnitBillings = [];
    $currencySum          = [];
    // 使用量をキャッシュする配列（同じユニット名の再計算防止）
    $usageCache           = [];

    $menus = $plan->getPricingMenus();
    foreach ($menus as $menu) {
      $menuName = $menu['display_name'] ?? '---';

      foreach ($menu['units'] ?? [] as $unit) {
        $unitType = $unit['type'] ?? 'usage';
        $unitName = $unit['metering_unit_name'] ?? '';
        $dispName = $unit['display_name'] ?? '';
        $currency = $unit['currency'] ?? 'JPY';
        $aggUsage = $unit['aggregate_usage'] ?? 'sum';

        $count = 0;
        // 固定課金でなければ使用量を計算
        if ($unitType !== 'fixed') {
          if (isset($usageCache[$unitName])) {
            $count = $usageCache[$unitName];
          } else {
            // メータリングユニットの使用量を取得
            $resp = $this->pricing
              ->getMeteringUnitDateCountByTenantIdAndUnitNameAndDatePeriod(
                $tenantId,
                $unitName,
                ['start_timestamp' => $start, 'end_timestamp' => $end]
              );
            $counts = $resp->getCounts();

            // 集計方法が 'max' の場合は最大値を取得
            if ($aggUsage === 'max') {
              $count = 0;
              foreach ($counts as $c) {
                $cnt = $c->getCount();
                if (is_numeric($cnt)) {
                  $count = max($count, (int)$cnt);
                }
              }
            } else {
              // デフォルトは合計値（sum）
              $count = array_reduce(
                $counts,
                fn($s, $c) => $s + (is_numeric($c->getCount()) ? (int)$c->getCount() : 0),
                0
              );
            }

            // 計算結果をキャッシュ
            $usageCache[$unitName] = $count;
          }
        }

        // 使用量とユニット情報から金額を計算
        $amount = $this->calcAmountByUnitType((int)$count, $unit);

        // 結果を配列に追加
        $meteringUnitBillings[] = [
          'metering_unit_name'        => $unitName,
          'metering_unit_type'        => $unitType,
          'function_menu_name'        => $menuName,
          'period_count'              => $count,
          'currency'                  => $currency,
          'period_amount'             => $amount,
          'pricing_unit_display_name' => $dispName,
        ];

        // 通貨ごとの合計金額を加算
        $currencySum[$currency] = ($currencySum[$currency] ?? 0) + $amount;
      }
    }

    // 通貨ごとの合計金額を配列化
    $currencyTotals = [];
    foreach ($currencySum as $cur => $sum) {
      $currencyTotals[] = ['currency' => $cur, 'total_amount' => $sum];
    }

    return [$meteringUnitBillings, $currencyTotals];
  }



  /**
   * unit タイプごとの金額計算
   *
   * @param int $count
   * @param array $unit 配列例:
   *   [
   *     'unit_amount' => int,
   *     'type' => string, // 'fixed'|'usage'|'tiered'|'tiered_usage'
   *     'tiers' => array, // (tiered/tiered_usage時)
   *     // 他に 'display_name', 'metering_unit_name', 'currency', など
   *   ]
   * @return int
   */
  private function calcAmountByUnitType(int $count, array $unit): int
  {
    $unitPrice = isset($unit['unit_amount']) ? (int)$unit['unit_amount'] : 0;
    $type      = $unit['type'] ?? 'usage';

    switch ($type) {
      case 'fixed':
        return $unitPrice;

      case 'usage':
        return $count * $unitPrice;

      case 'tiered':
        return $this->calcTiered($count, $unit);

      case 'tiered_usage':
        return $this->calcTieredUsage($count, $unit);

      default:
        return $count * $unitPrice;
    }
  }


  /**
   * tiered: その段階の flat + count * unit
   * 
   * $unit['tiers'] は以下のような配列を想定:
   * [
   *   [
   *     'up_to' => int,      // この段階の上限値
   *     'inf' => bool,       // trueなら無限大（最後の段階）
   *     'flat_amount' => int,// 固定金額
   *     'unit_amount' => int // 単価
   *   ],
   *   ...
   * ]
   * 指定された count が該当する段階の flat_amount + count * unit_amount を返す。
   */
  private function calcTiered(int $count, array $unit): int
  {
    $lastTier = null;
    foreach (($unit['tiers'] ?? []) as $tier) {
      $to   = $tier['up_to'] ?? 0;
      $inf  = $tier['inf']   ?? false;
      $lastTier = $tier;
      if ($inf || $count <= $to) {
        return ($tier['flat_amount'] ?? 0) + $count * ($tier['unit_amount'] ?? 0);
      }
    }
    // 全てのティアに該当しない場合は最大ティアを適用
    if ($lastTier !== null) {
      return ($lastTier['flat_amount'] ?? 0) + $count * ($lastTier['unit_amount'] ?? 0);
    }
    return 0;
  }

  /** tiered_usage: 累積計算 */
  private function calcTieredUsage(int $count, array $unit): int
  {
    $total   = 0;
    $prevTo  = 0;

    foreach (($unit['tiers'] ?? []) as $tier) {
      if ($count <= $prevTo) {
        break;
      }
      $to   = $tier['up_to'] ?? 0;
      $inf  = $tier['inf']   ?? false;

      $tierUsage = $inf ? $count - $prevTo
        : min($count, $to) - $prevTo;

      $total += ($tier['flat_amount'] ?? 0) + $tierUsage * ($tier['unit_amount'] ?? 0);
      $prevTo = $to;
    }
    return $total;
  }
}
