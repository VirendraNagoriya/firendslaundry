<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sms\SmsResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    /**
     * Consistent success envelope.
     */
    protected function ok(mixed $data = null, string $message = null, int $status = 200): JsonResponse
    {
        $payload = ['success' => true];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }

    protected function fail(string $message, int $status = 422, array $extra = []): JsonResponse
    {
        return response()->json(
            array_merge(['success' => false, 'message' => $message], $extra),
            $status
        );
    }

    protected function clientIp(Request $request): string
    {
        return (string) $request->ip();
    }

    /**
     * Describe an SMS outcome for an API response without leaking the
     * provider's raw payload to the browser.
     */
    protected function smsOutcome(SmsResult $result): array
    {
        return [
            'sms_accepted' => $result->accepted,
            'sms_error' => $result->error,
        ];
    }
}
