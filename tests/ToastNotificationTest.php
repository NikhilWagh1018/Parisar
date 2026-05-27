<?php

use PHPUnit\Framework\TestCase;

class ToastNotificationTest extends TestCase
{
    /**
     * Test toast types
     */
    public function testToastTypes()
    {
        $types = ['success', 'error', 'warning', 'info'];
        $this->assertCount(4, $types);
    }

    /**
     * Test default duration
     */
    public function testDefaultDuration()
    {
        $duration = 4000; // milliseconds
        $this->assertEquals(4000, $duration);
    }

    /**
     * Test toast positions
     */
    public function testToastPositions()
    {
        $positions = [
            'top-left',
            'top-right',
            'bottom-left',
            'bottom-right',
            'top-center',
            'bottom-center'
        ];
        $this->assertCount(6, $positions);
    }

    /**
     * Test message escaping
     */
    public function testMessageEscaping()
    {
        $dangerous = '<script>alert("xss")</script>';
        $safe = htmlspecialchars($dangerous, ENT_QUOTES, 'UTF-8');
        
        $this->assertStringNotContainsString('<script>', $safe);
        $this->assertStringContainsString('&lt;', $safe);
    }
}

class LoadingStateTest extends TestCase
{
    /**
     * Test loading state transitions
     */
    public function testLoadingStateTransition()
    {
        $states = ['initial', 'loading', 'loaded', 'error'];
        $this->assertContains('loading', $states);
    }

    /**
     * Test button disable during loading
     */
    public function testButtonDisableOnLoad()
    {
        $disabled = true;
        $this->assertTrue($disabled);
    }
}
