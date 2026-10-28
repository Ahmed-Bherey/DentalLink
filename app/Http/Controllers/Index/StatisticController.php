<?php

namespace App\Http\Controllers\Index;

use App\Http\Controllers\Controller;
use App\Services\Index\StatisticService;
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;

class StatisticController extends Controller
{
    use ApiResponse;

    protected $statisticService;

    public function __construct(StatisticService $statisticService)
    {
        $this->statisticService = $statisticService;
    }

    public function dashboardStats()
    {
        try {
            $this->authorize('view-dashboard');

            $user = request()->user();
            $stats = $this->statisticService->getDashboardStats($user);

            return $this->successResponse(
                $stats
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب الإحصائيات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }
}
