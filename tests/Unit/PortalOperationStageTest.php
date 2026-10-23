<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PortalOperationStage;
use PHPUnit\Framework\TestCase;

class PortalOperationStageTest extends TestCase
{
    public function test_an_unverified_upload_is_final_like_a_completed_or_failed_one(): void
    {
        $this->assertSame('unverified', PortalOperationStage::Unverified->value);

        $finalStages = array_values(array_filter(
            PortalOperationStage::cases(),
            fn (PortalOperationStage $stage): bool => $stage->isFinal()
        ));

        $this->assertSame(
            [PortalOperationStage::Completed, PortalOperationStage::Failed, PortalOperationStage::Unverified],
            $finalStages
        );
    }
}
