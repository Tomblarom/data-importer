<?php

/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Shared\Import\UploadReceiptStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UploadReceiptStoreTest extends TestCase
{
    private string $directory;
    private UploadReceiptStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/upload-receipt-test-'.bin2hex(random_bytes(8));
        $this->store = new UploadReceiptStore($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testInterruptedUploadRemainsClaimedAndCannotBeReplayed(): void
    {
        $id = 'test-import-0000000001';
        self::assertNull($this->store->claim($id, 'payload-hash'));
        $restarted = new UploadReceiptStore($this->directory);
        self::assertSame('processing', $restarted->claim($id, 'payload-hash')['status']);
        self::assertSame('processing', $restarted->read($id)['status']);
    }

    public function testCompletedReceiptSurvivesRestartWithoutExposingFingerprint(): void
    {
        $id = 'test-import-0000000002';
        $this->store->claim($id, 'payload-hash');
        $this->store->finish($id, ['status' => 'imported', 'converted_count' => 2]);
        $receipt = (new UploadReceiptStore($this->directory))->claim($id, 'payload-hash');
        self::assertSame('imported', $receipt['status']);
        self::assertSame(2, $receipt['converted_count']);
        self::assertArrayNotHasKey('fingerprint', $this->store->publicReceipt($receipt));
    }

    public function testSameIdentifierCannotBeUsedForDifferentPayload(): void
    {
        $id = 'test-import-0000000003';
        $this->store->claim($id, 'first');
        $this->expectException(RuntimeException::class);
        $this->store->claim($id, 'different');
    }

    public function testPathTraversalIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->store->read('../../sensitive-file');
    }
}
