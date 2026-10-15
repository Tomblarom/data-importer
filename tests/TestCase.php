<?php

/*
 * TestCase.php
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

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** @var list<callable> */
    private array $savedErrorHandlers = [];

    /** @var list<callable> */
    private array $savedExceptionHandlers = [];

    protected function setUp(): void
    {
        // Snapshot the complete pre-existing handler stacks (PHPUnit and
        // anything before it) before Laravel registers its own handlers.
        $this->savedErrorHandlers     = self::captureErrorHandlers();
        $this->savedExceptionHandlers = self::captureExceptionHandlers();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            // Laravel's HandleExceptions::flushHandlersState() clears all
            // pre-existing handlers. Reinstall the exact saved stacks so
            // PHPUnit's handlers survive; genuine test removals are still
            // detected by PHPUnit itself during the test run.
            self::reinstallErrorHandlers($this->savedErrorHandlers);
            self::reinstallExceptionHandlers($this->savedExceptionHandlers);
        }
    }

    /**
     * @return list<callable>
     */
    private static function captureErrorHandlers(): array
    {
        $collected = [];
        while (null !== ($top = get_error_handler())) {
            restore_error_handler();
            $collected[] = $top;
        }
        foreach (array_reverse($collected) as $handler) {
            set_error_handler($handler);
        }

        return $collected;
    }

    /**
     * @return list<callable>
     */
    private static function captureExceptionHandlers(): array
    {
        $collected = [];
        while (null !== ($top = get_exception_handler())) {
            restore_exception_handler();
            $collected[] = $top;
        }
        foreach (array_reverse($collected) as $handler) {
            set_exception_handler($handler);
        }

        return $collected;
    }

    /**
     * @param list<callable> $handlers
     */
    private static function reinstallErrorHandlers(array $handlers): void
    {
        while (null !== get_error_handler()) {
            restore_error_handler();
        }
        foreach (array_reverse($handlers) as $handler) {
            set_error_handler($handler);
        }
    }

    /**
     * @param list<callable> $handlers
     */
    private static function reinstallExceptionHandlers(array $handlers): void
    {
        while (null !== get_exception_handler()) {
            restore_exception_handler();
        }
        foreach (array_reverse($handlers) as $handler) {
            set_exception_handler($handler);
        }
    }
}
