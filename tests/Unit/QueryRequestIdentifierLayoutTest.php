<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class QueryRequestIdentifierLayoutTest extends TestCase
{
    public function test_request_ids_are_a_visible_searchable_first_column(): void
    {
        $pageSource = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/pages/query-requests/index.tsx',
        );
        $filterSource = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/crucible/query-request-filters.tsx',
        );

        $this->assertIsString($pageSource);
        $this->assertIsString($filterSource);
        $this->assertMatchesRegularExpression(
            '/<th[^>]*>\s*ID\s*<\/th>\s*<th[^>]*>\s*Request\s*<\/th>/',
            $pageSource,
        );
        $this->assertStringContainsString('{`#${request.id}`}', $pageSource);
        $this->assertStringContainsString('aria-label={`Open request #${request.id}`}', $pageSource);
        $this->assertStringContainsString(
            'placeholder="ID, request, requester, connection"',
            $filterSource,
        );
    }

    public function test_request_id_remains_visible_on_the_detail_page(): void
    {
        $pageSource = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/pages/query-requests/show.tsx',
        );

        $this->assertIsString($pageSource);
        $this->assertStringContainsString(
            'description={`Request #${query_request.id}`}',
            $pageSource,
        );
    }
}
