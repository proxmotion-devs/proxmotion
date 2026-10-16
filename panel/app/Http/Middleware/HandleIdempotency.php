<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Write requests with an Idempotency-Key header (docs/adr/0008): the first
 * answer per token and key is stored and replayed for the same request, so a
 * billing system that retries never starts an operation twice.
 */
class HandleIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);
        $token = $request->attributes->get('api_token');

        if (! is_string($key) || ! $token instanceof ApiToken) {
            return $next($request);
        }

        if (preg_match('/^[\x21-\x7E]{1,255}$/', $key) !== 1) {
            return response()->json(['message' => 'The Idempotency-Key header must be 1 to 255 visible ASCII characters.'], 422);
        }

        $hash = hash('sha256', $request->method()."\n".$request->path()."\n".$request->getContent());

        try {
            $record = new IdempotencyKey;
            $record->forceFill(['api_token_id' => $token->id, 'key' => $key, 'request_hash' => $hash])->save();
        } catch (UniqueConstraintViolationException) {
            return $this->replay($token, $key, $hash);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $record->delete();

            throw $e;
        }

        // Server errors are not stored: the client may try again with the same key.
        if ($response->getStatusCode() >= 500 || ! $response instanceof JsonResponse) {
            $record->delete();

            return $response;
        }

        $record->forceFill(['status_code' => $response->getStatusCode(), 'response' => $response->getContent()])->save();

        return $response;
    }

    private function replay(ApiToken $token, string $key, string $hash): Response
    {
        $record = IdempotencyKey::query()->where('api_token_id', $token->id)->where('key', $key)->first();

        if ($record === null) {
            // Deleted in between (a server error of the first request): ask the client to retry.
            return response()->json(['message' => 'The first request with this Idempotency-Key failed. Try again.'], 409);
        }

        if (! hash_equals($record->request_hash, $hash)) {
            return response()->json(['message' => 'This Idempotency-Key was already used for a different request.'], 422);
        }

        if ($record->status_code === null) {
            return response()->json(['message' => 'A request with this Idempotency-Key is still being processed.'], 409);
        }

        return response((string) $record->response, $record->status_code, [
            'Content-Type' => 'application/json',
            'Idempotent-Replayed' => 'true',
        ]);
    }
}
