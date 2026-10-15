<?php

/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Receipt uploads report safe counts; deep converter logs may contain bank data. */
final class PrivateUploadLogs
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->filled('import_id')) {
            return $next($request);
        }
        $previous = config('logging.default');
        config(['logging.channels.private-upload' => ['driver' => 'monolog', 'handler' => NullHandler::class],
            'logging.default' => 'private-upload']);
        try {
            return $next($request);
        } catch (Throwable) {
            // The durable receipt remains pending when execution did not finish cleanly.
            return response()->json(['status' => 'failed', 'error_code' => 'upload_failed'], 500)
                ->header('Cache-Control', 'no-store');
        } finally {
            config(['logging.default' => $previous]);
            Log::forgetChannel('private-upload');
        }
    }
}
