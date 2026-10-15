<?php

/*
 * BkbClient.php
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

namespace App\Services\Bkb;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the private BKB browser service.
 *
 * Every request carries the internal Bearer token. Nothing secret, and no
 * response body that could contain transactions, is ever written to the log.
 */
final class BkbClient
{
    public function configured(): bool
    {
        return '' !== $this->baseUrl() && '' !== (string) config('bkb.api_token');
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('bkb.bot_url', ''), '/');
    }

    /**
     * Bot state (setup_required, login_required, ready, fetching, ...).
     * Returns null when the service cannot be reached or answers badly.
     */
    public function getStatus(): ?array
    {
        $response = $this->send('get', '/v1/status', [], 10);
        if (null === $response || !$response->ok()) {
            return null;
        }
        $data = $response->json();

        return is_array($data) ? $data : null;
    }

    /**
     * Bot configuration. Returns null when unreachable or invalid.
     */
    public function getConfig(): ?array
    {
        $response = $this->send('get', '/v1/config', [], 10);
        if (null === $response || !$response->ok()) {
            return null;
        }
        $data = $response->json();

        return is_array($data) ? $data : null;
    }

    /**
     * Persist configuration fields on the bot.
     */
    public function updateConfig(array $fields): bool
    {
        $response = $this->send('post', '/v1/config', $fields, 15);

        return null !== $response && $response->ok();
    }

    /**
     * Open or restore the dedicated persistent bank browser.
     */
    public function openBrowser(): bool
    {
        $response = $this->send('post', '/v1/browser/open', [], 30);

        return null !== $response && $response->ok();
    }

    /**
     * Fetch the current browser screenshot. Returns the raw PNG bytes,
     * or null when unavailable.
     */
    public function getFrame(): ?string
    {
        $response = $this->send('get', '/v1/browser/frame', [], 30);
        if (null === $response || !$response->ok()) {
            return null;
        }
        $body = (string) $response->body();

        return '' === $body ? null : $body;
    }

    /**
     * Send one browser action (click, text, key or scroll).
     */
    public function sendAction(array $action): bool
    {
        $response = $this->send('post', '/v1/browser/action', $action, 30);

        return null !== $response && $response->ok();
    }

    /**
     * Fetch a sample CSV export. May take up to 120 seconds.
     * Returns the CSV text, or null on failure.
     */
    public function export(?string $startDate, ?string $endDate): ?string
    {
        $response = $this->send('post', '/v1/export', ['start_date' => $startDate, 'end_date' => $endDate], 120);
        if (null === $response || !$response->ok()) {
            return null;
        }
        $body = (string) $response->body();

        return '' === $body ? null : $body;
    }

    /**
     * Enqueue a background import. Returns the HTTP status code
     * (202 accepted, 409 busy or not ready), or null when unreachable.
     */
    public function sync(array $overrides = []): ?int
    {
        $response = $this->send('post', '/v1/sync', $overrides, 30);

        return $response?->status();
    }

    private function authenticated(): PendingRequest
    {
        return Http::withToken((string) config('bkb.api_token'))->acceptJson();
    }

    private function send(string $method, string $path, array $payload, int $timeout): ?\Illuminate\Http\Client\Response
    {
        if (!$this->configured()) {
            Log::warning('BKB browser service is not configured.');

            return null;
        }
        try {
            $request = $this->authenticated()->timeout($timeout);

            return 'get' === $method
                ? $request->get($this->baseUrl().$path)
                : $request->post($this->baseUrl().$path, $payload);
        } catch (ConnectionException) {
            // Deliberately terse: no URLs, tokens or response bodies in logs.
            Log::warning(sprintf('BKB browser service request failed (%s %s).', strtoupper($method), $path));

            return null;
        }
    }
}
