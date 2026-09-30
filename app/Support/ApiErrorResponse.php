<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ApiErrorResponse
{
    public static function render(Response $response): Response
    {
        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $status = $response->getStatusCode();
        $message = match ($status) {
            400 => "We couldn't understand that request. Check your details or filters and try again.",
            401 => 'Please sign in to continue.',
            403 => "You don't have permission to do that.",
            404 => "We couldn't find what you requested. It may have been removed.",
            419 => 'This page has expired. Reload it and try again.',
            422 => 'Please check the highlighted fields and try again.',
            429 => "You're making requests too quickly. Wait a moment and try again.",
            503 => 'This service is temporarily unavailable. Please try again shortly.',
            default => $status >= 500 ? 'Something went wrong on our side. Please try again.' : null,
        };

        if ($message !== null) {
            // Keep actionable field errors, but never send exception classes, SQL or traces.
            $data = ['message' => $message];
            if ($status === 422) {
                $data['errors'] = $response->getData(true)['errors'] ?? [];
            }
            $response->setData($data);
        }

        return $response;
    }
}
