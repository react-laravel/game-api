<?php

namespace App\Http\Controllers;

use App\Http\Resources\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * 返回成功响应
     */
    protected function success(mixed $data = null, string $message = 'Success', int $code = 200): JsonResponse
    {
        return ApiResponse::success($data, $message, $code);
    }

    /**
     * 返回错误响应
     */
    protected function error(string $message, mixed $data = null, int $code = 422): JsonResponse
    {
        return ApiResponse::error($message, $data, $code);
    }

    /**
     * 兼容旧的 fail 响应方法
     */
    protected function fail(string $message, mixed $errors = null, int $code = 422): JsonResponse
    {
        return $this->error($message, $errors, $code);
    }

    /**
     * 获取当前认证用户 ID
     */
    protected function getCurrentUserId(): int
    {
        return auth()->id();
    }
}
