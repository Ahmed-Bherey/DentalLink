<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\CategoryStoreRequest;
use App\Http\Requests\System\CategoryUpdateRequest;
use App\Http\Resources\System\CategoryResource;
use App\Models\General\Category;
use App\Services\System\CategoryService;
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
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
            $this->authorize('viewAny', Category::class);

            $categories = $this->categoryService->index();

            return $this->successResponse(CategoryResource::collection($categories), 200);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('عذرا حدث خطأ ما, برجاء المحاولة مرة اخرى', 422);
        }
    }

    // عرض بيانات تصنيف
    public function show($id)
    {
        try {
            $category = Category::findOrFail($id);

            $this->authorize('view', $category);

            return $this->successResponse(new CategoryResource($category), 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('القسم غير موجود', 404);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ ما أثناء جلب البيانات', 422);
        }
    }

    // انشاء تصنيف جديد
    public function store(CategoryStoreRequest $request)
    {
        try {
            $this->authorize('create', Category::class);

            $user = request()->user();
            $category = $this->categoryService->create($user, $request->validated());

            return $this->createSuccessResponse('تم انشاء القسم بنجاح', new CategoryResource($category));
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('عذرا حدث خطأ ما, برجاء المحاولة مرة اخرى', 422);
        }
    }

    // تحديث تصنيف
    public function update(CategoryUpdateRequest $request, $id)
    {
        try {
            $category = Category::findOrFail($id);

            $this->authorize('update', $category);

            $category = $this->categoryService->update($category, $request->validated());

            return $this->successResponse('تم تحديث القسم بنجاح', 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('القسم غير موجود', 404);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ ما أثناء التحديث', 422);
        }
    }

    // حذف تصنيف
    public function destroy($id)
    {
        try {
            $category = Category::findOrFail($id);

            $this->authorize('delete', $category);

            $this->categoryService->delete($category);

            return $this->successResponse('تم حذف القسم بنجاح', 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('القسم غير موجود', 404);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ ما أثناء الحذف', 422);
        }
    }
}
