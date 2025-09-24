<?php

namespace App\Http\Controllers\System;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\System\CategoryService;
use App\Http\Resources\System\CategoryResource;
use App\Http\Requests\System\CategoryStoreRequest;
use App\Http\Requests\System\CategoryUpdateRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CategoryController extends Controller
{
    use ApiResponse;
    protected $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    // عرض الكل
    public function index()
    {
        try {
            $categories = $this->categoryService->index();
            return $this->successResponse(CategoryResource::collection($categories), 200);
        } catch (Exception $e) {
            return $this->errorResponse('عذرا حدث خطأ ما, برجاء المحاولة مرة اخرى', 422);
        }
    }

    // عرض بيانات تصنيف
    public function show($id)
    {
        try {
            $user = request()->user();
            $category = $this->categoryService->show($user, $id);

            return $this->successResponseWithData('تم جلب بيانات القسم بنجاح', new CategoryResource($category), 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('القسم غير موجود أو لا يتبع هذا المستخدم', 404);
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ ما أثناء جلب البيانات', 500);
        }
    }

    // انشاء تصنيف جديد
    public function store(CategoryStoreRequest $request)
    {
        try {
            $user = request()->user();
            $category = $this->categoryService->create($user, $request->validated());
            return $this->successResponseWithId('تم انشاء القسم بنجاح', $category->id, 201);
        } catch (Exception $e) {
            return $this->errorResponse('عذرا حدث خطأ ما, برجاء المحاولة مرة اخرى', 422);
        }
    }

    // تحديث تصنيف
    public function update(CategoryUpdateRequest $request, $id)
    {
        try {
            $user = request()->user();
            $category = $this->categoryService->update($user, $id, $request->validated());

            return $this->successResponse('تم تحديث القسم بنجاح', 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('القسم غير موجود', 404);
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ ما أثناء التحديث', 422);
        }
    }

    // حذف تصنيف
    public function destroy($id)
    {
        try {
            $user = request()->user();
            $this->categoryService->delete($user, $id);

            return $this->successResponse('تم حذف القسم بنجاح', 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('القسم غير موجود', 404);
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ ما أثناء الحذف', 422);
        }
    }
}
