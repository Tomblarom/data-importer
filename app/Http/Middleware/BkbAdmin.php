<?php

/*
 * BkbAdmin.php
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

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the BKB management pages behind an independent administrative login.
 *
 * The public importer index is not an authentication barrier in this
 * deployment (the Firefly PAT is server configured), so BKB management
 * keeps its own session flag. When no BKB_ADMIN_PASSWORD_HASH is configured,
 * every protected route fails closed with a clear message.
 *
 * Every response passing through carries no-store headers.
 */
final class BkbAdmin
{
    public const string SESSION_KEY = 'bkb_admin_authenticated';

    public function handle(Request $request, Closure $next): Response
    {
        if ('' === (string) config('bkb.admin_hash')) {
            return $this->noStore(
                response()->view('import.bkb.login', $this->loginViewData(true), 403)
            );
        }

        if (true !== $request->session()->get(self::SESSION_KEY)) {
            return $this->noStore(redirect()->route('bkb.login'));
        }

        $response = $next($request);

        return $this->noStore($response);
    }

    /**
     * Shared data for the BKB login view.
     */
    public static function loginViewData(bool $disabled): array
    {
        return [
            'mainTitle' => 'BKB administration',
            'subTitle'  => 'BKB Digital Banking',
            'disabled'  => $disabled,
        ];
    }

    public static function addNoStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    private function noStore(Response $response): Response
    {
        return self::addNoStore($response);
    }
}
