<?php

namespace App\Support;

use App\Models\ErrorEvent;
use App\Models\Module;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ErrorEventRecorder
{
    public function record(Request $request, Throwable $exception, int $statusCode): string
    {
        return Impersonation::allowingWrites(fn (): string => $this->persist($request, $exception, $statusCode));
    }

    private function persist(Request $request, Throwable $exception, int $statusCode): string
    {
        do {
            $reference = Str::upper(bin2hex(random_bytes(4)));
            $formattedReference = substr($reference, 0, 4).'-'.substr($reference, 4);
        } while (ErrorEvent::query()->where('reference', $formattedReference)->exists());

        $moduleSlug = null;

        if (
            $statusCode === 403
            && preg_match('/^(?:no_access|read_only):(.+)$/', $exception->getMessage(), $matches) === 1
        ) {
            $moduleSlug = Module::query()
                ->where('name', trim($matches[1]))
                ->value('slug');
        }

        $exceptionFile = $exception->getFile();
        $exceptionLine = $exception->getLine();
        $trace = array_map(
            static fn (array $frame): array => array_filter([
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
                'function' => $frame['function'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            array_slice($exception->getTrace(), 0, 15),
        );

        $fingerprint = hash('sha256', $exception::class."\0".$exceptionFile."\0".$exceptionLine);
        $isNewServerErrorGroup = $statusCode === 500
            && ! ErrorEvent::query()->where('fingerprint', $fingerprint)->exists();

        $event = ErrorEvent::query()->create([
            'reference' => $formattedReference,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'status_code' => $statusCode,
            'module_slug' => $moduleSlug,
            'path' => mb_substr($request->getPathInfo(), 0, 2048),
            'method' => $request->method(),
            'exception_class' => $exception::class,
            'message' => $statusCode === 403 ? 'Access denied.' : 'An unexpected server error occurred.',
            'exception_message' => $exception->getMessage(),
            'exception_file' => mb_substr($exceptionFile, 0, 2048),
            'exception_line' => $exceptionLine > 0 ? $exceptionLine : null,
            'trace' => $trace,
            'fingerprint' => $fingerprint,
            'role_slug' => $request->user()?->role?->slug,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        if ($isNewServerErrorGroup) {
            app(NotificationService::class)->errorGroupDetected($event);
        }

        return $formattedReference;
    }
}
