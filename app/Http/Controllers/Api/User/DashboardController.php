<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\DashboardResource;
use App\Models\Order;
use App\Services\DashboardAlertsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

use function array_slice;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardAlertsService $alertsService,
    ) {}

    public function index(): JsonResponse
    {
        $user = Auth::user();

        $latestOrder = Order::where(function ($q) use ($user) {
            $q->where(Order::PLACER_USER_ID, $user->id)
                ->orWhere(Order::BENEFICIARY_USER_ID, $user->id);
        })
            ->latest()
            ->first();

        $data = [
            'active_order' => $latestOrder,
            'quick_access' => $this->getQuickAccessLinks($user->id),
            'alerts' => $this->alertsService->collectAlerts($user),
        ];

        return success(new DashboardResource($data), __('dashboard.success'));
    }

    private function getQuickAccessLinks(int $userId): array
    {
        $allModules = Config::get('dashboard.modules');
        $defaults = Config::get('dashboard.defaults');

        $usage = Cache::get("user_dashboard_usage:{$userId}", []);

        arsort($usage);

        $topKeys = array_keys(array_slice($usage, 0, 5, true));

        if (empty($topKeys)) {
            $topKeys = $defaults;
        }

        if (count($topKeys) < 5) {
            foreach ($defaults as $def) {
                if (! in_array($def, $topKeys)) {
                    $topKeys[] = $def;
                }
                if (count($topKeys) >= 5) {
                    break;
                }
            }
        }

        $links = [];
        foreach ($topKeys as $key) {
            if (isset($allModules[$key])) {
                $links[] = [
                    'key' => $key,
                    'title' => $allModules[$key]['title'],
                    'icon' => $allModules[$key]['icon'],
                    'link' => $allModules[$key]['link'],
                ];
            }
        }

        return $links;
    }
}
