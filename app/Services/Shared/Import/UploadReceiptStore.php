<?php

/*
 * UploadReceiptStore.php
 * Copyright (c) 2026 Firefly III contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Services\Shared\Import;

use RuntimeException;

/** Durable opt-in upload receipts. An interrupted upload is never automatically replayed. */
final class UploadReceiptStore
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not initialize upload receipts.');
        }
    }

    public function read(string $id): ?array
    {
        $path = $this->path($id);
        if (!is_file($path)) {
            return null;
        }

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Returns a previous receipt, or null when this caller claimed a new upload. */
    public function claim(string $id, string $fingerprint): ?array
    {
        $lock = fopen($this->path($id).'.lock', 'c');
        if (false === $lock || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock upload receipt.');
        }
        try {
            $previous = $this->read($id);
            if (null !== $previous) {
                if (!hash_equals($previous['fingerprint'], $fingerprint)) {
                    throw new RuntimeException('Import identifier belongs to a different payload.');
                }

                return $previous;
            }
            $this->write($id, ['import_id' => $id, 'status' => 'processing', 'fingerprint' => $fingerprint]);

            return null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function finish(string $id, array $report): array
    {
        $previous = $this->read($id);
        if (null === $previous) {
            throw new RuntimeException('Upload was not claimed.');
        }
        $receipt = array_merge($previous, $report, ['import_id' => $id]);
        $this->write($id, $receipt);

        return $receipt;
    }

    public function publicReceipt(array $receipt): array
    {
        unset($receipt['fingerprint']);

        return $receipt;
    }

    private function path(string $id): string
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]{16,64}\z/', $id)) {
            throw new RuntimeException('Invalid import identifier.');
        }

        return $this->directory.'/'.$id.'.json';
    }

    private function write(string $id, array $receipt): void
    {
        $temporary = tempnam($this->directory, '.receipt-');
        if (false === $temporary) {
            throw new RuntimeException('Could not write upload receipt.');
        }
        try {
            $handle = fopen($temporary, 'wb');
            if (false === $handle) {
                throw new RuntimeException('Could not open upload receipt.');
            }
            fwrite($handle, json_encode($receipt, JSON_THROW_ON_ERROR));
            fflush($handle);
            fsync($handle);
            fclose($handle);
            chmod($temporary, 0600);
            if (!rename($temporary, $this->path($id))) {
                throw new RuntimeException('Could not finalize upload receipt.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
