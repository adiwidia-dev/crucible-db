<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class QueryRequestTargetSummaryLayoutTest extends TestCase
{
    public function test_large_target_lists_are_summarized_and_expandable(): void
    {
        $pageSource = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/pages/query-requests/show.tsx',
        );

        $this->assertIsString($pageSource);
        $this->assertStringContainsString(
            'const summarizedTargetConnections = targetConnections.slice(0, 3);',
            $pageSource,
        );
        $this->assertStringContainsString(
            'const additionalTargetConnections = targetConnections.slice(3);',
            $pageSource,
        );
        $this->assertStringContainsString('<CollapsibleTrigger asChild>', $pageSource);
        $this->assertStringContainsString('variant="link"', $pageSource);
        $this->assertStringContainsString('more targets`}', $pageSource);
        $this->assertStringContainsString('Show fewer targets', $pageSource);
        $this->assertStringContainsString('<CollapsibleContent>', $pageSource);
        $this->assertStringContainsString(
            '`Targets · ${targetConnections.length} total`',
            $pageSource,
        );

        $targetSummaryPosition = strpos(
            $pageSource,
            '{summarizedTargetConnections.map',
        );
        $this->assertIsInt($targetSummaryPosition);

        $collapsibleContentPosition = strpos(
            $pageSource,
            '<CollapsibleContent>',
            $targetSummaryPosition,
        );
        $collapsibleTriggerPosition = strpos(
            $pageSource,
            '<CollapsibleTrigger asChild>',
            $targetSummaryPosition,
        );

        $this->assertIsInt($collapsibleContentPosition);
        $this->assertIsInt($collapsibleTriggerPosition);
        $this->assertGreaterThan(
            $collapsibleContentPosition,
            $collapsibleTriggerPosition,
            'The show more/fewer control should render after the target list.',
        );
    }
}
