<?php

/*
 * AuthenticationValidator.php
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

use App\Services\Enums\AuthenticationStatus;
use App\Services\Shared\Authentication\AuthenticationValidatorInterface;
use Illuminate\Support\Facades\Log;

/**
 * Class AuthenticationValidator
 *
 * Reports only whether the private browser service is configured and
 * reachable. Bank state (login, accounts, errors) is never exposed here;
 * it stays behind the administrative BKB management pages.
 */
final class AuthenticationValidator implements AuthenticationValidatorInterface
{
    public function validate(): AuthenticationStatus
    {
        Log::debug(sprintf('Now at %s', __METHOD__));
        $client = new BkbClient();
        if (!$client->configured()) {
            Log::debug('BKB browser service is not configured.');

            return AuthenticationStatus::NODATA;
        }
        if (null === $client->getStatus()) {
            Log::warning('BKB browser service is configured but unreachable.');

            return AuthenticationStatus::ERROR;
        }
        Log::debug('BKB browser service is configured and reachable.');

        return AuthenticationStatus::AUTHENTICATED;
    }

    public function getData(): array
    {
        return [];
    }

    public function setData(array $data): void {}
}
