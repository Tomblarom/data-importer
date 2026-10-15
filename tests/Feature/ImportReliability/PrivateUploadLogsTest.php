<?php

/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace Tests\Feature\ImportReliability;

use App\Http\Middleware\PrivateUploadLogs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

final class PrivateUploadLogsTest extends TestCase
{
    public function testRenderedServerErrorIsSanitizedAndNormalLoggerIsRestored(): void
    {
        $before = config('logging.default');
        $request = Request::create('/autoupload', 'POST', ['import_id' => 'test-import-000001']);
        $response = (new PrivateUploadLogs())->handle($request, function () {
            self::assertSame('private-upload', Log::getDefaultDriver());
            Log::error('fixture-bank-detail-must-never-reach-normal-logger');

            return response('<html>fixture-secret-exception-and-stack</html>', 500);
        });
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('fixture-secret', $response->getContent());
        self::assertStringContainsString('upload_failed', $response->getContent());
        self::assertSame($before, config('logging.default'));
        self::assertSame($before, Log::getDefaultDriver());
    }

    public function testUnhandledExceptionDoesNotExposeItsMessage(): void
    {
        $before = config('logging.default');
        $request = Request::create('/autoupload', 'POST', ['import_id' => 'test-import-000002']);
        $response = (new PrivateUploadLogs())->handle($request, static function () {
            throw new RuntimeException('fixture-private-bank-value');
        });
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('fixture-private', $response->getContent());
        self::assertSame($before, Log::getDefaultDriver());
    }

    public function testLegacyUploadKeepsItsExistingLoggerAndResponse(): void
    {
        $before = config('logging.default');
        $response = (new PrivateUploadLogs())->handle(Request::create('/autoupload', 'POST'), static fn () => response('legacy', 200));
        self::assertSame('legacy', $response->getContent());
        self::assertSame($before, Log::getDefaultDriver());
    }
}
