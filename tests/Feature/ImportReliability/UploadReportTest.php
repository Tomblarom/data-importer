<?php

/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace Tests\Feature\ImportReliability;

use App\Http\Controllers\AutoUploadController;
use App\Models\ImportJob;
use App\Services\Shared\Conversion\ConversionStatus;
use App\Services\Shared\Import\Status\SubmissionStatus;
use ReflectionClass;
use Tests\TestCase;

final class UploadReportTest extends TestCase
{
    private function report(string $error): array
    {
        $job = ImportJob::createNew();
        $job->identifier = 'fixture-job';
        $job->conversionStatus->setStatus(ConversionStatus::CONVERSION_DONE);
        $job->submissionStatus->setStatus(SubmissionStatus::SUBMISSION_DONE);
        $job->submissionStatus->addError(0, $error);
        $reflection = new ReflectionClass(AutoUploadController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('importJob')->setValue($controller, $job);

        return $reflection->getMethod('uploadReport')->invoke($controller);
    }

    public function testActualDuplicateIsAnExpectedSkip(): void
    {
        $report = $this->report('[a115]: Transaction already exists.');
        self::assertSame('imported', $report['status']);
        self::assertSame(1, $report['duplicate_count']);
        self::assertSame(0, $report['error_count']);
    }

    public function testValidationErrorContainingDuplicateTextIsStillAFailure(): void
    {
        $report = $this->report('[a117]: Invalid original value containing [a115]: marker.');
        self::assertSame('failed', $report['status']);
        self::assertSame(0, $report['duplicate_count']);
        self::assertSame(1, $report['error_count']);
    }
}
