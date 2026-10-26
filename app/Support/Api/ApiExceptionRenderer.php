<?php

namespace App\Support\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Formats ANY exception thrown on an `/api/*` request into a consistent JSON
 * envelope so the Flutter client never receives an HTML error page.
 *
 * FORMATTING ONLY — this class makes no authorization, permission, role, policy
 * or business decision. It just maps an already-thrown exception to its JSON
 * shape and status code. The decision to throw (e.g. `abort(403)`) is untouched.
 *
 * Envelope: { "success": false, "message": string, "errors"?: object }
 */
class ApiExceptionRenderer
{
    /**
     * Generic English permission strings emitted by the existing `abort(403, …)`
     * calls. When a 403 carries one of these we replace it with the standard
     * localized message; any OTHER 403 message (e.g. "Job sudah di-approve …")
     * is preserved so we don't lose meaningful, intentional feedback.
     */
    private const GENERIC_403_NEEDLES = [
        'you dont have permission',
        'you do not have permission',
        'permission to access',
        'permission to perform',
        'this action is unauthorized', // Laravel default Gate/Policy denial
        'unauthorized',
        'forbidden',
    ];

    public static function render(Throwable $e): JsonResponse
    {
        // Validation — keep the field errors (reaches here before Laravel's own
        // conversion because render callbacks run first).
        if ($e instanceof ValidationException) {
            return self::json(422, 'Validation failed.', $e->errors());
        }

        // Not authenticated (no/invalid token).
        if ($e instanceof AuthenticationException) {
            return self::json(401, 'Unauthenticated.');
        }

        // Authorization (Gate/Policy denial) — Laravel usually pre-converts this
        // to AccessDeniedHttpException before callbacks run, but handle the raw
        // type too for safety.
        if ($e instanceof AuthorizationException) {
            return self::json(403, self::messageForStatus(403, $e->getMessage()));
        }

        // Eloquent findOrFail etc. (also normally pre-converted to 404).
        if ($e instanceof ModelNotFoundException) {
            return self::json(404, 'Data tidak ditemukan.');
        }

        // Any HTTP exception incl. abort(403)/abort(404)/405/419/429 …
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return self::json($status, self::messageForStatus($status, $e->getMessage()));
        }

        // Anything else = unexpected server error.
        $payload = [
            'success' => false,
            'message' => 'Terjadi kesalahan pada server.',
        ];
        // Surface the real reason ONLY in debug builds — never leak it in prod,
        // and never as HTML.
        if (config('app.debug')) {
            $payload['debug'] = $e->getMessage();
            $payload['exception'] = get_class($e);
        }

        return response()->json($payload, 500);
    }

    private static function messageForStatus(int $status, ?string $rawMessage): string
    {
        $raw = trim((string) $rawMessage);

        return match ($status) {
            400 => $raw !== '' ? $raw : 'Permintaan tidak valid.',
            401 => 'Unauthenticated.',
            403 => self::resolve403Message($raw),
            404 => 'Data tidak ditemukan.',
            405 => 'Metode permintaan tidak diizinkan server.',
            413 => 'Ukuran total foto terlalu besar. Maksimal 128 MB per permintaan.',
            419 => 'Sesi kedaluwarsa. Silakan login ulang.',
            422 => $raw !== '' ? $raw : 'Validation failed.',
            429 => 'Terlalu banyak permintaan. Coba lagi sebentar.',
            default => $status >= 500
                ? 'Terjadi kesalahan pada server.'
                : ($raw !== '' ? $raw : "Permintaan gagal diproses ($status)."),
        };
    }

    private static function resolve403Message(string $raw): string
    {
        $standard = 'Anda tidak memiliki akses untuk tindakan ini.';

        if ($raw === '') {
            return $standard;
        }

        $lower = strtolower($raw);
        foreach (self::GENERIC_403_NEEDLES as $needle) {
            if (str_contains($lower, $needle)) {
                return $standard;
            }
        }

        // A deliberate, specific 403 message (e.g. "Job sudah di-approve …") is
        // kept as-is — that is feedback, not permission logic.
        return $raw;
    }

    private static function json(int $status, string $message, ?array $errors = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
