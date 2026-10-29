<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/RoadCatalogue.php';

class RoadCatalogueTest extends TestCase
{
    public function test_a_road_no_audit_uses_can_be_deleted(): void
    {
        $this->assertNull(roadDeleteBlockedReason(0));
    }

    public function test_a_road_used_in_an_audit_cannot_be_deleted(): void
    {
        $this->assertSame(
            'This road is used in an audit. Remove it from the audit first.',
            roadDeleteBlockedReason(1)
        );
        $this->assertNotNull(roadDeleteBlockedReason(12));
    }
}
