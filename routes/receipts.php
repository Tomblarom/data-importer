<?php

/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::post('/import-receipt', 'ImportReceiptController@index')->name('import-receipt');
