<?php

/*
 * ImportReceiptController.php
 * Copyright (c) 2026 Firefly III contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Shared\Import\UploadReceiptStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportReceiptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $systemSecret = (string) config('importer.auto_import_secret');
        $secret = (string) $request->input('secret', '');
        if (strlen($systemSecret) < 16 || !hash_equals($systemSecret, $secret)) {
            return response()->json(['error' => 'Authentication required.'], 401)->header('Cache-Control', 'no-store');
        }
        $validated = $request->validate(['import_id' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_-]{16,64}\z/']]);
        $store = new UploadReceiptStore(storage_path('import-receipts'));
        $receipt = $store->read($validated['import_id']);
        if (null === $receipt) {
            return response()->json(['status' => 'unknown'], 404)->header('Cache-Control', 'no-store');
        }

        return response()->json($store->publicReceipt($receipt))->header('Cache-Control', 'no-store');
    }
}
