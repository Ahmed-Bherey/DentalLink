<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

trait ApiResponse
{
    public function successResponse($data = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'status'  => true,
            'data'    => $data,
        ], $code);
    }

    public function successResponseWithoutData($msg = null, int $code = 201): JsonResponse
    {
        return response()->json([
            'status'  => true,
            'msg'    => $msg,
        ], $code);
    }

    public function createSuccessResponse($msg = null, $data = null, int $code = 201): JsonResponse
    {
        return response()->json([
            'status'  => true,
            'msg'  => $msg,
            'data'  => $data,
        ], $code);
    }

    public function successResponseWithId($msg = null, $record_id = null, int $code = 201): JsonResponse
    {
        return response()->json([
            'status'  => true,
            'msg'  => $msg,
            'record_id'  => $record_id,
        ], $code);
    }

    public function errorResponse(string $message = null, int $code = 500): JsonResponse
    {
        return response()->json([
            'status'  => false,
            'message' => $message ?? 'Something went wrong',
        ], $code);
    }

    public function paginatedResponse($data, $paginator, int $code = 200): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data'   => $data,
            'meta'   => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            'links'  => [
                'first' => $paginator->url(1),
                'last'  => $paginator->url($paginator->lastPage()),
                'prev'  => $paginator->previousPageUrl(),
                'next'  => $paginator->nextPageUrl(),
            ]
        ], $code);
    }
}
