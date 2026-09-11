<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SidebarNavigationStructureTest extends TestCase
{
    public function test_sidebar_uses_the_approved_navigation_organization(): void
    {
        $sidebarSource = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/app-sidebar.tsx',
        );

        $this->assertIsString($sidebarSource);
        $this->assertStringContainsString('label="Manage"', $sidebarSource);
        $this->assertStringContainsString('title="Administration"', $sidebarSource);
        $this->assertStringNotContainsString('label="Admin"', $sidebarSource);

        $previousSectionPosition = -1;

        foreach ([
            "label: 'Access & identity'",
            "label: 'Security & policy'",
            "label: 'Application'",
            "label: 'Governance'",
        ] as $sectionLabel) {
            $sectionPosition = strpos($sidebarSource, $sectionLabel);

            $this->assertNotFalse($sectionPosition);
            $this->assertGreaterThan($previousSectionPosition, $sectionPosition);

            $previousSectionPosition = $sectionPosition;
        }

        $sidebarFooterPosition = strpos($sidebarSource, '<SidebarFooter');
        $accountPosition = strpos($sidebarSource, 'title="Account"');

        $this->assertNotFalse($sidebarFooterPosition);
        $this->assertNotFalse($accountPosition);
        $this->assertGreaterThan($sidebarFooterPosition, $accountPosition);
        $this->assertStringNotContainsString('label="Account"', $sidebarSource);
        $this->assertStringContainsString("title: 'Sign-in Methods'", $sidebarSource);
        $this->assertStringContainsString("title: 'SSO Providers'", $sidebarSource);
        $this->assertStringContainsString(
            'href: authProvidersIndex()',
            $sidebarSource,
        );
        $this->assertStringContainsString(
            'isActive: isCurrentOrParentUrl(authProvidersIndex())',
            $sidebarSource,
        );
    }

    public function test_admin_section_state_is_not_reset_when_the_url_changes(): void
    {
        $navigationSource = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/nav-main.tsx',
        );

        $this->assertIsString($navigationSource);
        $this->assertStringContainsString(
            'const [sectionOpenStates, setSectionOpenStates]',
            $navigationSource,
        );
        $this->assertStringContainsString(
            'onNavigate={preserveActiveSections}',
            $navigationSource,
        );
        $this->assertStringNotContainsString(
            'openOverride?.url === currentUrl',
            $navigationSource,
        );
    }

    public function test_administration_and_account_disclosures_are_independent(): void
    {
        $sidebarSource = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/app-sidebar.tsx',
        );

        $this->assertIsString($sidebarSource);
        $this->assertStringContainsString(
            'const [openNavigationGroups, setOpenNavigationGroups]',
            $sidebarSource,
        );
        $this->assertStringContainsString(
            'open={openNavigationGroups.administration}',
            $sidebarSource,
        );
        $this->assertStringContainsString(
            'open={openNavigationGroups.account}',
            $sidebarSource,
        );
        $this->assertStringNotContainsString(
            "group: isOpen ? 'administration' : null",
            $sidebarSource,
        );
        $this->assertStringNotContainsString(
            "group: isOpen ? 'account' : null",
            $sidebarSource,
        );
    }

    public function test_explicit_navigation_active_states_are_respected(): void
    {
        $navigationSource = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/nav-main.tsx',
        );

        $this->assertIsString($navigationSource);
        $this->assertStringContainsString(
            'item.isActive || isCurrentUrl(item.href)',
            $navigationSource,
        );
        $this->assertStringNotContainsString('uppercase', $navigationSource);
        $this->assertStringNotContainsString(
            'tracking-[0.08em]',
            $navigationSource,
        );
    }

    public function test_authentication_pages_use_the_navigation_labels(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $signInMethodsSource = file_get_contents(
            $projectRoot.'/resources/js/pages/settings/admin/authentication.tsx',
        );
        $providerIndexSource = file_get_contents(
            $projectRoot.'/resources/js/pages/settings/authentication-providers/index.tsx',
        );
        $providerFormSource = file_get_contents(
            $projectRoot.'/resources/js/pages/settings/authentication-providers/form.tsx',
        );

        $this->assertIsString($signInMethodsSource);
        $this->assertIsString($providerIndexSource);
        $this->assertIsString($providerFormSource);
        $this->assertStringContainsString(
            'title="Sign-in methods"',
            $signInMethodsSource,
        );
        $this->assertStringContainsString(
            'Manage SSO providers',
            $signInMethodsSource,
        );
        $this->assertStringContainsString(
            'title="SSO providers"',
            $providerIndexSource,
        );
        $this->assertStringContainsString(
            "title: 'SSO Providers'",
            $providerFormSource,
        );
    }
}
