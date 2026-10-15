<?php

/*
 * BkbController.php
 * Copyright (c) 2026 james@firefly-iii.org
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

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Http\Middleware\BkbAdmin;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\Bkb\BkbClient;
use App\Services\Shared\Configuration\Configuration;
use App\Services\Shared\File\FileContentSherlock;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use JsonException;
use Throwable;

/**
 * BKB Digital Banking: administrative management of the private browser
 * service plus the file-flow handoff for first-time column mapping.
 *
 * The connector itself is never exposed: the browser sees only these
 * PHP endpoints, which proxy to the internal bot API with a server-side
 * Bearer token. No token, password or typed text ever hits a log, a
 * session or a view.
 */
final class BkbController extends Controller
{
    private BkbClient $client;
    private ImportJobRepository $repository;

    public function __construct()
    {
        parent::__construct();
        app('view')->share('pageTitle', 'BKB Digital Banking');
        $this->client     = new BkbClient();
        $this->repository = new ImportJobRepository();
    }

    /**
     * GET /authenticate-flow/bkb — the provider entry point.
     */
    public function authenticate(): RedirectResponse
    {
        return redirect()->route('bkb.index');
    }

    /**
     * GET /new-import/bkb — the provider entry point.
     */
    public function newImport(): RedirectResponse
    {
        return redirect()->route('bkb.index');
    }

    /**
     * POST /new-import/bkb — no direct upload form exists for BKB,
     * everything runs through management.
     */
    public function newImportPost(): RedirectResponse
    {
        return redirect()->route('bkb.index');
    }

    /**
     * GET /bkb — management page in the regular importer layout.
     */
    public function index(): View
    {
        Log::debug(sprintf('Now at %s', __METHOD__));
        $status    = $this->client->getStatus();
        $config    = $this->client->getConfig();
        $available = null !== $status && null !== $config;

        $importConfig = is_array($config) && isset($config['import_config']) && is_array($config['import_config'])
            ? $config['import_config']
            : null;

        return view(
            'import.bkb.index',
            [
                'mainTitle'       => 'BKB Digital Banking',
                'subTitle'        => 'Management and browser',
                'available'       => $available,
                'state'           => is_array($status) ? (string) ($status['state'] ?? 'unknown') : 'unavailable',
                'authenticated'   => is_array($status) ? (bool) ($status['authenticated'] ?? false) : false,
                'botPaused'       => is_array($status) ? (bool) ($status['paused'] ?? true) : true,
                'lastSuccess'     => is_array($status) ? ($status['last_success'] ?? null) : null,
                'nextRun'         => is_array($status) ? ($status['next_run'] ?? null) : null,
                'lastError'       => is_array($status) ? ($status['last_error'] ?? null) : null,
                'configReady'     => is_array($status) ? (bool) ($status['configuration_ready'] ?? false) : false,
                'accountUrl'      => is_array($config) ? (string) ($config['account_url'] ?? '') : '',
                'startDate'       => is_array($config) ? ($config['start_date'] ?? null) : null,
                'endDate'         => is_array($config) ? ($config['end_date'] ?? null) : null,
                'intervalSeconds' => is_array($config) ? (int) ($config['interval_seconds'] ?? 3600) : 3600,
                'paused'          => is_array($config) ? (bool) ($config['paused'] ?? true) : true,
                'importConfig'    => null === $importConfig ? '' : (string) json_encode($importConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'keepalive'       => is_array($status) && isset($status['keepalive']) && is_array($status['keepalive']) ? $status['keepalive'] : [],
                'pendingImport'   => is_array($status) ? (bool) ($status['pending_import'] ?? false) : false,
                'rangeVerified'   => is_array($status) ? (bool) ($status['last_export_range_verified'] ?? false) : false,
                'viewportWidth'   => (int) config('bkb.viewport.width', 1440),
                'viewportHeight'  => (int) config('bkb.viewport.height', 1000),
            ]
        );
    }

    /**
     * GET /bkb/login
     */
    public function showLogin(): Response
    {
        if ($this->adminDisabled()) {
            return BkbAdmin::addNoStore(response()->view('import.bkb.login', BkbAdmin::loginViewData(true), 403));
        }

        return BkbAdmin::addNoStore(response()->view('import.bkb.login', BkbAdmin::loginViewData(false)));
    }

    /**
     * POST /bkb/login
     */
    public function login(Request $request): RedirectResponse
    {
        if ($this->adminDisabled()) {
            abort(403, 'BKB management is disabled.');
        }
        $validated = $request->validate(['password' => 'required|string']);
        $hash      = (string) config('bkb.admin_hash');

        if (password_verify((string) $validated['password'], $hash)) {
            $request->session()->regenerate();
            $request->session()->put(BkbAdmin::SESSION_KEY, true);
            Log::info('BKB administrator login successful.');

            return BkbAdmin::addNoStore(redirect()->route('bkb.index'));
        }

        Log::warning('BKB administrator login failed.');

        return BkbAdmin::addNoStore(redirect()->route('bkb.login')->withErrors(['password' => 'Invalid credentials.']));
    }

    /**
     * POST /bkb/logout
     */
    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(BkbAdmin::SESSION_KEY);
        $request->session()->regenerate();

        return BkbAdmin::addNoStore(redirect()->route('bkb.login'));
    }

    /**
     * GET /bkb/frame — proxied browser screenshot, never cached.
     */
    public function frame(): Response
    {
        $png = $this->client->getFrame();
        if (null === $png) {
            abort(502, 'The bank view is currently unavailable.');
        }

        return response(
            $png,
            200,
            [
                'Content-Type'  => 'image/png',
                'Content-Length' => (string) strlen($png),
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma'        => 'no-cache',
            ]
        );
    }

    /**
     * POST /bkb/browser/open
     */
    public function open(): JsonResponse
    {
        if (!$this->client->openBrowser()) {
            return response()->json(['ok' => false, 'message' => 'Could not open the bank browser.'], 502);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /bkb/browser/action — click, text, key or scroll.
     */
    public function action(Request $request): JsonResponse
    {
        // The bot viewport is 0-indexed: the extreme pixel is size - 1.
        $maxX = (int) config('bkb.viewport.width', 1440) - 1;
        $maxY = (int) config('bkb.viewport.height', 1000) - 1;

        // Validated manually (never via $request->validate()) so typed bank
        // text is never flashed into the session on validation errors.
        $validator = Validator::make(
            $request->all(),
            [
                'type'    => 'required|in:click,text,key,scroll',
                'x'       => sprintf('required_if:type,click|numeric|min:0|max:%d', $maxX),
                'y'       => sprintf('required_if:type,click|numeric|min:0|max:%d', $maxY),
                'text'    => 'required_if:type,text|string|max:500',
                'key'     => 'required_if:type,key|in:Enter,Tab,Backspace',
                'delta_y' => 'required_if:type,scroll|numeric|min:-2000|max:2000',
            ]
        );
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'message' => 'Invalid browser action.'], 422);
        }
        $validated = $validator->validated();

        $action = match ($validated['type']) {
            'click'  => ['type' => 'click', 'x' => (int) $validated['x'], 'y' => (int) $validated['y']],
            'text'   => ['type' => 'text', 'text' => (string) $validated['text']],
            'key'    => ['type' => 'key', 'key' => (string) $validated['key']],
            'scroll' => ['type' => 'scroll', 'delta_y' => (int) $validated['delta_y']],
        };

        if (!$this->client->sendAction($action)) {
            return response()->json(['ok' => false, 'message' => 'The bank browser did not accept the action.'], 502);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /bkb/config — persist the bot configuration.
     */
    public function saveConfig(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            [
                'account_url'      => 'nullable|url|starts_with:https://',
                'start_date'       => 'nullable|date',
                'end_date'         => 'nullable|date',
                'interval_seconds' => 'nullable|integer|min:3600|max:604800',
                'paused'           => 'nullable|boolean',
                'import_config'    => [
                    'nullable',
                    'string',
                    'max:131072',
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (null === $value || '' === trim((string) $value)) {
                            return;
                        }
                        try {
                            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
                        } catch (JsonException) {
                            $fail('The import configuration is not valid JSON.');

                            return;
                        }
                        if (!is_array($decoded)) {
                            $fail('The import configuration is not valid JSON.');
                        }
                    },
                ],
            ]
        );

        $start = !empty($validated['start_date']) ? (string) $validated['start_date'] : null;
        $end   = !empty($validated['end_date']) ? (string) $validated['end_date'] : null;
        if (null !== $start && null !== $end && strtotime((string) $end) < strtotime($start)) {
            return redirect()->route('bkb.index')->withErrors(['end_date' => 'The end date cannot be before the start date.'])->withInput();
        }

        $rawImportConfig = trim((string) ($validated['import_config'] ?? ''));
        $payload         = [
            'account_url'      => '' === trim((string) ($validated['account_url'] ?? '')) ? null : trim((string) $validated['account_url']),
            'start_date'       => $start,
            'end_date'         => $end,
            'interval_seconds' => isset($validated['interval_seconds']) ? (int) $validated['interval_seconds'] : 3600,
            'paused'           => $request->boolean('paused'),
            'import_config'    => '' === $rawImportConfig ? null : json_decode($rawImportConfig, true),
        ];

        if (!$this->client->updateConfig($payload)) {
            return redirect()->route('bkb.index')->withErrors(['config' => 'Could not save the configuration.'])->withInput();
        }

        return redirect()->route('bkb.index')->with('status', 'Configuration saved.');
    }

    /**
     * POST /bkb/sample — fetch a sample CSV and start the normal
     * file-flow mapping flow (no custom converter involved).
     */
    public function sample(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            [
                'start_date' => 'nullable|date',
                'end_date'   => 'nullable|date',
            ]
        );
        $start     = !empty($validated['start_date']) ? (string) $validated['start_date'] : null;
        $end       = !empty($validated['end_date']) ? (string) $validated['end_date'] : null;

        $csv = $this->client->export($start, $end);
        if (null === $csv) {
            return redirect()->route('bkb.index')->withErrors(['sample' => 'Could not fetch a sample CSV. The service may be busy or not ready.']);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'bkb-sample-');
        if (false === $tmp) {
            return redirect()->route('bkb.index')->withErrors(['sample' => 'Could not fetch a sample CSV.']);
        }
        file_put_contents($tmp, $csv);
        $type = (new FileContentSherlock())->detectContentType($tmp);
        @unlink($tmp);

        if ('csv' !== $type) {
            Log::warning('BKB sample export was not recognised as CSV.');

            return redirect()->route('bkb.index')->withErrors(['sample' => 'The sample export was not a CSV file.']);
        }

        // Reuse a stored import configuration (exported/importer format) when present.
        $configContent = '';
        $botConfig     = $this->client->getConfig();
        if (is_array($botConfig) && isset($botConfig['import_config']) && is_array($botConfig['import_config'])) {
            $configContent = (string) json_encode($botConfig['import_config']);
        }

        $importJob = $this->repository->create();
        $importJob = $this->repository->setFlow($importJob, 'file');
        $importJob = $this->repository->setConfigurationString($importJob, $configContent);
        $importJob = $this->repository->setImportableFileString($importJob, $csv);
        $importJob = $this->repository->markAs($importJob, 'contains_content');

        // The normalized bot CSV uses semicolons and a header row.
        $configuration = Configuration::fromArray(['delimiter' => ';']);
        $configuration->setHeaders(true);
        if ('' !== $configContent) {
            try {
                $decoded = json_decode($configContent, true);
                if (is_array($decoded)) {
                    $configuration = Configuration::fromArray($decoded);
                }
            } catch (Throwable) {
                // Fall back to the preset configuration; mapping starts fresh.
                $configuration = Configuration::fromArray(['delimiter' => ';']);
                $configuration->setHeaders(true);
            }
        }
        $configuration->setFlow($importJob->getFlow());
        $importJob->setConfiguration($configuration);
        $this->repository->saveToDisk($importJob);

        return redirect()->route('configure-import.index', [$importJob->identifier]);
    }

    /**
     * POST /bkb/sync — enqueue a background import (202 accepted).
     */
    public function syncNow(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            [
                'start_date' => 'nullable|date',
                'end_date'   => 'nullable|date',
            ]
        );
        $overrides = [];
        if (!empty($validated['start_date'])) {
            $overrides['start_date'] = (string) $validated['start_date'];
        }
        if (!empty($validated['end_date'])) {
            $overrides['end_date'] = (string) $validated['end_date'];
        }

        $code = $this->client->sync($overrides);
        if (202 === $code) {
            return redirect()->route('bkb.index')->with('status', 'Import queued.');
        }
        if (409 === $code) {
            return redirect()->route('bkb.index')->withErrors(['sync' => 'The service is busy, or setup and login are not complete.']);
        }

        return redirect()->route('bkb.index')->withErrors(['sync' => 'Could not queue an import.']);
    }

    /**
     * POST /bkb/pause and POST /bkb/resume
     */
    public function pause(): RedirectResponse
    {
        if (!$this->client->updateConfig(['paused' => true])) {
            return redirect()->route('bkb.index')->withErrors(['pause' => 'Could not pause automatic imports.']);
        }

        return redirect()->route('bkb.index')->with('status', 'Automatic imports paused.');
    }

    public function resume(): RedirectResponse
    {
        if (!$this->client->updateConfig(['paused' => false])) {
            return redirect()->route('bkb.index')->withErrors(['pause' => 'Could not resume automatic imports.']);
        }

        return redirect()->route('bkb.index')->with('status', 'Automatic imports resumed.');
    }

    private function adminDisabled(): bool
    {
        return '' === (string) config('bkb.admin_hash');
    }
}
