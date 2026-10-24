<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * API error from the spec: code is the machine-readable error, message is
 * optional.
 */
final class ApiError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message = '',
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $message): self
    {
        return new self(400, 'bad_request', $message);
    }

    public function toResponse(): JsonResponse
    {
        $body = ['error' => $this->errorCode];
        if ($this->getMessage() !== '') {
            $body['message'] = $this->getMessage();
        }

        return response()->json($body, $this->status);
    }
}
