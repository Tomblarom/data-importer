<?php

/*
 * BkbTest.php
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

namespace Tests\Feature\Bkb;

use App\Http\Middleware\BkbAdmin;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class BkbTest extends TestCase
{
    private string $adminPassword = 'correct-horse-admin';

    protected function setUp(): void
    {
        parent::setUp();
        // Views are asserted as rendered HTML; compiled frontend assets are
        // smoke-tested separately against real builds, never here.
        $this->withoutVite();
        config()->set('bkb.bot_url', 'http://bkb-bot:8080');
        config()->set('bkb.api_token', 'test-token');
        config()->set('bkb.admin_hash', password_hash($this->adminPassword, PASSWORD_BCRYPT, ['cost' => 4]));
    }

    public function testProviderIsRegistered(): void
    {
        $this->get('/api/import-flows')->assertOk()
            ->assertJsonFragment(['key' => 'bkb', 'title' => 'BKB Digital Banking']);
    }

    public function testValidationOkWhenServiceReachable(): void
    {
        Http::fake(['http://bkb-bot:8080/v1/status' => Http::response($this->statusPayload(), 200)]);

        $this->get('/api/import-flows/validate/bkb')->assertOk()->assertJson(['result' => 'OK']);
        $this->get('/validate/bkb')->assertOk()->assertJson(['result' => 'OK']);
    }

    public function testValidationNodataWhenUnconfigured(): void
    {
        config()->set('bkb.api_token', '');

        $this->get('/api/import-flows/validate/bkb')->assertOk()->assertJson(['result' => 'NODATA']);
    }

    public function testValidationNokWhenServiceDown(): void
    {
        Http::fake(['http://bkb-bot:8080/v1/status' => Http::response([], 500)]);

        $this->get('/api/import-flows/validate/bkb')->assertOk()->assertJson(['result' => 'NOK']);
    }

    public function testEntryPointsOpenManagement(): void
    {
        $this->get('/authenticate-flow/bkb')->assertRedirect(route('bkb.index'));
        $this->get('/new-import/bkb')->assertRedirect(route('bkb.index'));
    }

    public function testManagementRequiresAdminLogin(): void
    {
        $this->get('/bkb')->assertRedirect(route('bkb.login'));
        $this->get('/bkb/frame')->assertRedirect(route('bkb.login'));
        $this->post('/bkb/sync', ['_token' => csrf_token()])->assertRedirect(route('bkb.login'));
    }

    public function testManagementFailClosedWithoutHash(): void
    {
        config()->set('bkb.admin_hash', '');

        $this->get('/bkb')->assertForbidden();
        $this->get('/bkb')->assertSee('no administrative password is configured');
        $this->get('/bkb/login')->assertForbidden();
        $this->post('/bkb/login', ['password' => $this->adminPassword, '_token' => csrf_token()])->assertForbidden();
        $this->get('/bkb/frame')->assertForbidden();
    }

    public function testLoginPageRenders(): void
    {
        $this->get('/bkb/login')->assertOk()->assertSee('Administrative password', false);
    }

    public function testLoginRequiresCsrfToken(): void
    {
        // Prove the real CSRF middleware: testing environments may bypass
        // it, so run this single test as a local environment.
        $this->app['env'] = 'local';

        $this->post('/bkb/login', ['password' => $this->adminPassword])->assertStatus(419);
    }

    public function testLoginRejectsWrongPassword(): void
    {
        $this->get('/bkb/login')->assertOk();
        $this->post('/bkb/login', ['password' => 'wrong-password', '_token' => csrf_token()])
            ->assertRedirect(route('bkb.login'))
            ->assertSessionHasErrors('password');

        $this->assertFalse(session()->has(BkbAdmin::SESSION_KEY));
        $this->get('/bkb')->assertRedirect(route('bkb.login'));
    }

    public function testLoginAcceptsCorrectPassword(): void
    {
        Http::fake(
            [
                'http://bkb-bot:8080/v1/status' => Http::response($this->statusPayload(), 200),
                'http://bkb-bot:8080/v1/config' => Http::response($this->configPayload(), 200),
            ]
        );
        $this->get('/bkb/login')->assertOk();
        $this->post('/bkb/login', ['password' => $this->adminPassword, '_token' => csrf_token()])
            ->assertRedirect(route('bkb.index'));

        $this->get('/bkb')->assertOk()
            ->assertSee('ready', false)
            ->assertSee('not verified', false)
            ->assertSee('ping', false)
            ->assertDontSee('test-token')
            ->assertHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function testSaveConfigRejectsShortInterval(): void
    {
        $this->get('/bkb/login')->assertOk();
        $this->withSession([BkbAdmin::SESSION_KEY => true])->post(
            '/bkb/config',
            [
                '_token'           => csrf_token(),
                'account_url'      => 'https://ebanking.bkb.ch/statements',
                'interval_seconds' => 60,
                'paused'           => '1',
            ]
        )->assertSessionHasErrors('interval_seconds');

        Http::assertNothingSent();
    }

    public function testLogoutClearsSession(): void
    {
        $this->get('/bkb/login')->assertOk();
        $this->withSession([BkbAdmin::SESSION_KEY => true])
            ->post('/bkb/logout', ['_token' => csrf_token()])
            ->assertRedirect(route('bkb.login'));

        $this->get('/bkb')->assertRedirect(route('bkb.login'));
    }

    public function testBrowserActionValidation(): void
    {
        Http::fake(['http://bkb-bot:8080/v1/browser/action' => Http::response(['ok' => true], 200)]);
        $session = [BkbAdmin::SESSION_KEY => true];

        // unknown type, missing coordinate, out-of-range coordinate.
        $this->withSession($session)->postJson('/bkb/browser/action', ['_token' => csrf_token(), 'type' => 'banana'])->assertStatus(422);
        $this->withSession($session)->postJson('/bkb/browser/action', ['_token' => csrf_token(), 'type' => 'click', 'x' => 10])->assertStatus(422);
        $this->withSession($session)->postJson(
            '/bkb/browser/action',
            ['_token' => csrf_token(), 'type' => 'click', 'x' => 2000, 'y' => 10]
        )->assertStatus(422);
        $this->withSession($session)->postJson(
            '/bkb/browser/action',
            ['_token' => csrf_token(), 'type' => 'key', 'key' => 'Escape']
        )->assertStatus(422);

        // rejected banking text is never flashed into the session.
        $this->withSession($session)->postJson(
            '/bkb/browser/action',
            ['_token' => csrf_token(), 'type' => 'text']
        )->assertStatus(422);
        $this->assertFalse(session()->has('_old_input'));

        // a click on the extreme viewport pixel is proxied with the internal Bearer token.
        $this->withSession($session)->postJson(
            '/bkb/browser/action',
            ['_token' => csrf_token(), 'type' => 'click', 'x' => 1439, 'y' => 999]
        )->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(
            static fn ($request): bool => str_ends_with($request->url(), '/v1/browser/action')
                && 'Bearer test-token' === $request->header('Authorization')[0]
        );
    }

    public function testFrameProxiesPngWithoutCaching(): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        Http::fake(['http://bkb-bot:8080/v1/browser/frame' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $response = $this->withSession([BkbAdmin::SESSION_KEY => true])->get('/bkb/frame');
        $response->assertOk()->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->assertSame($png, $response->getContent());

        Http::assertSent(
            static fn ($request): bool => str_ends_with($request->url(), '/v1/browser/frame')
                && 'Bearer test-token' === $request->header('Authorization')[0]
        );
    }

    public function testSampleCreatesFileFlowJob(): void
    {
        Storage::fake('import-jobs');
        Http::fake(
            [
                'http://bkb-bot:8080/v1/export' => Http::response("Datum;Text;Betrag\n01.01.2026;Coffee;3.50\n", 200, ['Content-Type' => 'text/csv']),
                'http://bkb-bot:8080/v1/config' => Http::response($this->configPayload(), 200),
            ]
        );

        $this->get('/bkb/login')->assertOk();
        $response = $this->withSession([BkbAdmin::SESSION_KEY => true])
            ->post('/bkb/sample', ['_token' => csrf_token()]);

        $target     = (string) $response->getTargetUrl();
        $identifier = basename($target);
        $response->assertRedirect(route('configure-import.index', [$identifier]));

        $disk = Storage::disk('import-jobs');
        $this->assertTrue($disk->exists(sprintf('%s.json', $identifier)));
        $job = json_decode((string) $disk->get(sprintf('%s.json', $identifier)), true);
        $this->assertSame('file', $job['flow']);
        $this->assertStringContainsString('Datum;Text;Betrag', (string) $job['importable_file_string']);
    }

    public function testRecoverAcknowledgesFailedImport(): void
    {
        Http::fake(
            [
                'http://bkb-bot:8080/v1/status'  => Http::response($this->pendingStatusPayload(), 200),
                'http://bkb-bot:8080/v1/config'  => Http::response($this->configPayload(), 200),
                'http://bkb-bot:8080/v1/recover' => Http::response(['ok' => true], 200),
            ]
        );

        // the button is offered only while an import is pending.
        $this->withSession([BkbAdmin::SESSION_KEY => true])->get('/bkb')->assertOk()
            ->assertSee('Acknowledge failed import', false);

        $this->get('/bkb/login')->assertOk();
        $this->withSession([BkbAdmin::SESSION_KEY => true])
            ->post('/bkb/recover', ['_token' => csrf_token()])
            ->assertRedirect(route('bkb.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        Http::assertSent(
            static fn ($request): bool => str_ends_with($request->url(), '/v1/recover')
                && 'Bearer test-token' === $request->header('Authorization')[0]
                && [] === $request->data()
        );
    }

    public function testRecoverStaysBlockedWhileProcessing(): void
    {
        Http::fake(['http://bkb-bot:8080/v1/recover' => Http::response(['detail' => 'still processing'], 409)]);

        $this->get('/bkb/login')->assertOk();
        $this->withSession([BkbAdmin::SESSION_KEY => true])
            ->post('/bkb/recover', ['_token' => csrf_token()])
            ->assertRedirect(route('bkb.index'))
            ->assertSessionHasErrors('recover');
    }

    public function testRecoverButtonHiddenWithoutPendingImport(): void
    {
        Http::fake(
            [
                'http://bkb-bot:8080/v1/status' => Http::response($this->statusPayload(), 200),
                'http://bkb-bot:8080/v1/config' => Http::response($this->configPayload(), 200),
            ]
        );

        $this->withSession([BkbAdmin::SESSION_KEY => true])->get('/bkb')->assertOk()
            ->assertDontSee('Acknowledge failed import', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingStatusPayload(): array
    {
        $payload                   = $this->statusPayload();
        $payload['state']          = 'error';
        $payload['pending_import'] = true;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(): array
    {
        return [
            'state'                      => 'ready',
            'authenticated'              => true,
            'paused'                     => true,
            'last_success'               => null,
            'next_run'                   => '2026-10-16T01:00:00+00:00',
            'last_error'                 => null,
            'configuration_ready'        => true,
            'keepalive'                  => [
                'strategy'      => 'ping',
                'last_verified' => '2026-10-15T12:00:00+00:00',
                'observations'  => [],
            ],
            'pending_import'             => false,
            'last_export_range_verified' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function configPayload(): array
    {
        return [
            'account_url'      => null,
            'start_date'       => null,
            'end_date'         => null,
            'interval_seconds' => 3600,
            'paused'           => true,
            'import_config'    => null,
        ];
    }
}
