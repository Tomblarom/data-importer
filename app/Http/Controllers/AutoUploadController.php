<?php

/*
 * AutoUploadController.php
 * Copyright (c) 2025 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Console\AutoImports;
use App\Console\HaveAccess;
use App\Console\VerifyJSON;
use App\Exceptions\ImporterErrorException;
use App\Http\Request\AutoUploadRequest;
use GrumpyDictator\FFIIIApiSupport\Exceptions\ApiHttpException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Services\Shared\Import\UploadReceiptStore;
use RuntimeException;

final class AutoUploadController extends Controller
{
    private bool $receiptMode = false;
    use AutoImports;
    use HaveAccess;
    use VerifyJSON;

    /**
     * @throws ImporterErrorException
     */
    public function index(AutoUploadRequest $request): JsonResponse
    {
        if (false === config('importer.can_post_files')) {
            throw new ImporterErrorException('Please set CAN_POST_AUTOIMPORT=true for this function to work.');
        }
        $secret         = (string) ($request->input('secret') ?? '');
        $systemSecret   = (string) config('importer.auto_import_secret');
        if ('' === $secret || '' === $systemSecret || !hash_equals($secret, (string) config('importer.auto_import_secret')) || strlen($systemSecret) < 16) {
            throw new ImporterErrorException('Please make sure your secret value matches whatever is in AUTO_IMPORT_SECRET.');
        }

        $access         = $this->haveAccess(false);
        if (false === $access) {
            throw new ImporterErrorException(sprintf('Could not connect / get access to your local Firefly III instance at %s.', config('importer.url')));
        }
        $json           = $request->file('json');
        $importable     = $request->file('importable');
        $importablePath = (string) $importable?->getPathname();

        $importId = (string) $request->input('import_id', '');
        $this->receiptMode = '' !== $importId;
        $receipts = null;
        if ('' !== $importId) {
            $receipts = new UploadReceiptStore(storage_path('import-receipts'));
            $fingerprint = hash('sha256', file_get_contents((string) $json?->getPathname())."\0".
                ('' === $importablePath ? '' : file_get_contents($importablePath)));
            try {
                $previous = $receipts->claim($importId, $fingerprint);
            } catch (RuntimeException) {
                return response()->json(['error' => 'Upload identifier could not be claimed.'], 409)->header('Cache-Control', 'no-store');
            }
            if (null !== $previous) {
                $code = 'imported' === $previous['status'] ? 200 : 409;

                return response()->json($receipts->publicReceipt($previous), $code)->header('Cache-Control', 'no-store');
            }
        }

        try {
            $this->importUpload((string) $json?->getPathname(), $importablePath);
        } catch (ApiHttpException|ImporterErrorException $e) {
            if (null !== $receipts) {
                $receipt = $receipts->finish($importId, ['status' => 'failed', 'error_count' => 1]);

                return response()->json($receipts->publicReceipt($receipt), 422)->header('Cache-Control', 'no-store');
            }
            $message = sprintf('[%s]: %s', config('importer.version'), $e->getMessage());
            Log::error($message);

            return response()->json(['error' => sprintf('Translate the import result to a JSON thing. Also error: %s', $message)], 400);
        }

        if (null !== $receipts) {
            $receipt = $receipts->finish($importId, $this->uploadReport());
            $code = 'imported' === $receipt['status'] ? 200 : 422;

            return response()->json($receipts->publicReceipt($receipt), $code)->header('Cache-Control', 'no-store');
        }

        return response()->json(['message' => 'Translate the import result to a JSON thing.']);
    }

    public function error($string, $verbosity = null): void
    {
        if ($this->receiptMode) {
            return;
        }
        Log::error($string);

        // $this->line($string);
    }

    public function line(string $string): void
    {
        if ($this->receiptMode) {
            return;
        }
        Log::info($string);

        // echo sprintf("%s: %s\n", Carbon::now()->format('Y-m-d H:i:s'), $string);
    }

    /**
     * @param null  $verbosity
     * @param mixed $string
     */
    public function info($string, $verbosity = null): void
    {
        if ($this->receiptMode) {
            return;
        }
        Log::info($string);

        // $this->line($string);
    }

    /**
     * @param null  $verbosity
     * @param mixed $string
     */
    public function warn($string, $verbosity = null): void
    {
        if ($this->receiptMode) {
            return;
        }
        Log::warning($string);

        // $this->line($string);
    }
}
