<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class QueryRequestExecutionHistoryLayoutTest extends TestCase
{
    public function test_execution_history_pagination_keeps_the_table_in_view(): void
    {
        $pageSource = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/pages/query-requests/show.tsx',
        );
        $paginationSource = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/crucible/pagination.tsx',
        );

        $this->assertIsString($pageSource);
        $this->assertIsString($paginationSource);
        $this->assertStringContainsString('id="execution-history"', $pageSource);
        $this->assertStringContainsString('anchor="execution-history"', $pageSource);
        $this->assertStringContainsString('preserveState', $pageSource);
        $this->assertStringContainsString('anchor?: string;', $paginationSource);
        $this->assertMatchesRegularExpression(
            '/href=\\{\\s*anchor \\? `\\$\\{link\\.url\\}#\\$\\{anchor\\}` : link\\.url\\s*\\}/',
            $paginationSource,
        );
        $this->assertStringContainsString('preserveState={preserveState}', $paginationSource);
    }

    public function test_expanded_results_are_contained_in_their_own_scroll_region(): void
    {
        $pageSource = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/pages/query-requests/show.tsx',
        );

        $this->assertIsString($pageSource);
        $this->assertStringContainsString('colSpan={9}', $pageSource);
        $this->assertStringContainsString(
            'className="w-0 max-w-0 px-4 py-3 sm:px-6"',
            $pageSource,
        );
        $this->assertStringContainsString(
            'aria-label="Execution result rows"',
            $pageSource,
        );
        $this->assertStringContainsString(
            'className="w-max min-w-full text-sm"',
            $pageSource,
        );
        $this->assertStringContainsString(
            'max-h-80 max-w-full overflow-auto overscroll-x-contain',
            $pageSource,
        );
    }
}
