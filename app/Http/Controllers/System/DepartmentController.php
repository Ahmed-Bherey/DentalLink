<?php

namespace App\Http\Controllers\System;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\System\DepartmentResource;
use App\Services\System\DepartmentService;

class DepartmentController extends Controller
{
    use ApiResponse;
    protected $departmentService;

    public function __construct(DepartmentService $departmentService)
    {
        $this->departmentService = $departmentService;
    }

    public function index()
    {
        try {
            $departments = $this->departmentService->getAll();
            return $this->successResponse(DepartmentResource::collection($departments), 200);
        } catch (Exception $e) {
            return $this->errorResponse('عذرا حدث خطأ ما, برجاء المحاولة مرة اخرى', 422);
        }
    }

    // إنشاء قسم جديد
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => 'required|string|max:255',
                'desc' => 'nullable|string',
                'code' => 'required|string|unique:departments,code',
            ]);

            $department = $this->departmentService->create($data);

            return $this->createSuccessResponse('تم انشاء القسم بنجاح', new DepartmentResource($department));
        } catch (Exception $e) {
            return $this->errorResponse('عذرا حدث خطأ ما, برجاء المحاولة مرة اخرى', 422);
        }
    }
}
