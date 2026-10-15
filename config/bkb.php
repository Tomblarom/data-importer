<?php

/*
 * bkb.php
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

/*
 * Configuration for the BKB Digital Banking provider. The importer talks to
 * a private browser service ("bkb-bot") over HTTP. That service is never
 * exposed to the browser; all contact goes through server-side PHP proxies.
 *
 * Secrets may be provided either directly (BKB_API_TOKEN) or through a file
 * (BKB_API_TOKEN_FILE, Docker secrets style). The *_FILE variant wins when
 * it points to a readable, non-empty file.
 */

$readSecret = static function (string $name, string $default = ''): string {
    $fileVar = (string) env($name.'_FILE', '');
    if ('' !== $fileVar && is_readable($fileVar)) {
        $content = trim((string) @file_get_contents($fileVar));
        if ('' !== $content) {
            return $content;
        }
    }

    return (string) env($name, $default);
};

return [
    // Base URL of the private browser service, e.g. http://bkb-bot:8080.
    'bot_url'    => rtrim((string) env('BKB_BOT_URL', 'http://bkb-bot:8080'), '/'),

    // Internal Bearer token for the browser service API. Never rendered.
    'api_token'  => $readSecret('BKB_API_TOKEN'),

    // PHP password_hash() of the administrative password that gates the
    // BKB management pages. When empty, management is disabled entirely.
    'admin_hash' => $readSecret('BKB_ADMIN_PASSWORD_HASH'),

    // Fixed native viewport of the bank browser, in pixels. The management
    // page renders screenshots at this intrinsic resolution and transforms
    // click coordinates back into it.
    'viewport'   => [
        'width'  => 1440,
        'height' => 1000,
    ],
];
