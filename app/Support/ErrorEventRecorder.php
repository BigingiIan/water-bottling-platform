<?php

namespace App\Support;

use App\Models\ErrorEvent;
use App\Models\Module;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ErrorEventRecorder
{
    public function record(Request $request, Throwable $exception, int $statusCode): string
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

        $message = $statusCode === 403 ? 'Access denied.' : 'An unexpected server error occurred.';

        ErrorEvent::query()->create([
            'reference' => $formattedReference,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'status_code' => $statusCode,
            'module_slug' => $moduleSlug,
            'url' => mb_substr($request->url(), 0, 2048),
            'method' => $request->method(),
            'exception_class' => $exception::class,
            'message' => mb_substr($message, 0, 500),
            'created_at' => now(),
        ]);

        return $formattedReference;
    }
}
