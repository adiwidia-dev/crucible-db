<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NotificationIdentifierLayoutTest extends TestCase
{
    public function test_request_ids_are_visible_in_the_notification_center_and_history(): void
    {
        $notificationCenterSource = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/app-sidebar-header.tsx',
        );
        $notificationHistorySource = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/pages/notifications/index.tsx',
        );

        $this->assertIsString($notificationCenterSource);
        $this->assertIsString($notificationHistorySource);

        foreach ([$notificationCenterSource, $notificationHistorySource] as $source) {
            $this->assertStringContainsString(
                'notification.request_id !=',
                $source,
            );
            $this->assertStringContainsString(
                'notification.request_id',
                $source,
            );
            $this->assertStringContainsString('font-mono', $source);
            $this->assertStringContainsString('tabular-nums', $source);
        }
    }
}
