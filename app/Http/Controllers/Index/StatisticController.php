<?php

namespace App\Http\Controllers\Index;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\Index\StatisticService;

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
        //try {
            $user = request()->user();
            $stats = $this->statisticService->getDashboardStats($user);

            return $this->successResponse(
                $stats
            );
        // } catch (Exception $e) {
        //     return $this->errorResponse(
        //         'عذراً، حدث خطأ أثناء جلب الإحصائيات. برجاء المحاولة لاحقاً',
        //         422
        //     );
        // }
    }
}
