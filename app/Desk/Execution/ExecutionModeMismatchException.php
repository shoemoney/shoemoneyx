<?php

declare(strict_types=1);

namespace App\Desk\Execution;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when a caller tries to settle a position through an executor whose mode does not match
 * the position's own stored mode (a paper position closed by the live executor, or vice versa).
 * Renders itself as 409 so controllers do not need their own exception mapping.
 */
class ExecutionModeMismatchException extends \RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['error' => $this->getMessage()], 409);
    }
}
