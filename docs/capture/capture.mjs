import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { chromium } from '@playwright/test';

const baseUrl = process.env.DOCS_BASE_URL ?? 'http://localhost:8000';
const outputDirectory = resolve('docs/assets/screenshots');
const viewport = { width: 1440, height: 900 };
const password = process.env.DOCS_DEMO_PASSWORD ?? 'password';
const invitationUrl = process.env.DOCS_INVITATION_URL;
const captureScope = process.env.DOCS_CAPTURE_SCOPE ?? 'all';
const accounts = {
    admin: process.env.DOCS_ADMIN_EMAIL ?? 'admin@example.com',
    requester: process.env.DOCS_REQUESTER_EMAIL ?? 'developer@example.com',
    reviewer: process.env.DOCS_REVIEWER_EMAIL ?? 'reviewer@example.com',
    twoFactor: process.env.DOCS_TWO_FACTOR_EMAIL ?? 'twofactor@example.com',
};

await mkdir(outputDirectory, { recursive: true });

const browser = await chromium.launch();

try {
    if (captureScope === 'all' || captureScope === 'public') {
        await capturePublicPages();
    }

    if (captureScope === 'all' || captureScope === 'requester') {
        await captureRequesterPages();
    }

    if (captureScope === 'all' || captureScope === 'reviewer') {
        await captureReviewerPages();
    }

    if (captureScope === 'all' || captureScope === 'admin') {
        await captureAdministratorPages();
    }

    if (captureScope === 'admin-details') {
        await captureAdministratorDetailPages();
    }

    console.info(`Captured the ${captureScope} documentation screenshot set.`);
} finally {
    await browser.close();
}

async function capturePublicPages() {
    const { context, page } = await createPage();

    try {
        await visit(page, '/login');
        await screenshot(page, 'sign-in.png', true);
        await visit(page, '/forgot-password');
        await screenshot(page, 'forgot-password.png', true);
        await visit(
            page,
            '/reset-password/documentation-token?email=developer%40example.com',
        );
        await screenshot(page, 'reset-password.png', true);

        if (invitationUrl) {
            await visit(page, invitationUrl);
            await screenshot(page, 'accept-invitation.png', true);
        }
    } finally {
        await context.close();
    }

    await captureTwoFactorChallenge();
}

async function captureRequesterPages() {
    const { context, page } = await authenticatedPage(accounts.requester);

    try {
        await captureRoutes(page, [
            ['/dashboard', 'user-overview.png'],
            ['/query-requests', 'user-query-requests.png'],
            ['/connections', 'user-connections.png'],
            ['/notifications', 'user-notifications.png'],
            ['/settings/profile', 'account-profile.png'],
            ['/settings/preferences', 'account-preferences.png'],
            ['/settings/security', 'account-security.png'],
            ['/query-requests/create', 'new-query-request.png'],
        ]);

        await visit(page, '/dashboard');
        await page
            .getByRole('button', {
                name: /unread notifications|notifications/i,
            })
            .click();
        await screenshot(page, 'notification-menu.png');

        await visit(page, '/connections');
        await visitLinkedPage(page, 'Production Orders');
        await screenshot(page, 'user-connection-detail.png');

        await visit(page, '/query-requests/create');
        await page.getByText('Query Access', { exact: true }).click();
        await screenshot(page, 'new-query-access-request.png');

        await captureRequest(
            page,
            'Draft: review unsupported maintenance SQL',
            'blocked-draft.png',
        );
        await captureRequest(
            page,
            'Verify reporting replica readiness',
            'completed-request.png',
        );

        await visit(page, '/query-requests');
        await visitLinkedPage(
            page,
            'Approved Query Access: inspect checkout timing',
        );
        const sessionHref = await page
            .getByRole('link', { name: 'Resume Session', exact: true })
            .getAttribute('href');

        if (!sessionHref) {
            throw new Error('The active documentation session is missing.');
        }

        await visit(page, new URL(sessionHref, baseUrl).pathname);
        await screenshot(page, 'query-access-session.png');

        await visit(page, '/query-requests');
        await visitLinkedPage(
            page,
            'Native Client: investigate checkout timing',
        );
        const nativeSessionHref = await page
            .getByRole('link', { name: 'Resume Session', exact: true })
            .getAttribute('href');

        if (!nativeSessionHref) {
            throw new Error('The native documentation session is missing.');
        }

        await visit(page, new URL(nativeSessionHref, baseUrl).pathname);
        await screenshot(page, 'native-client-session.png');
    } finally {
        await context.close();
    }
}

async function captureReviewerPages() {
    const { context, page } = await authenticatedPage(accounts.reviewer);

    try {
        await visit(page, '/dashboard');
        await screenshot(page, 'reviewer-overview.png');

        await visit(page, '/query-requests');
        const pendingHref = await page
            .getByRole('link', {
                name: 'DEP-2042: Correct account status',
                exact: true,
            })
            .getAttribute('href');

        if (!pendingHref) {
            throw new Error('The pending documentation request is missing.');
        }

        await visit(page, new URL(pendingHref, baseUrl).pathname);
        await screenshot(page, 'reviewer-pending-request.png');
        await page.locator('#review-request').scrollIntoViewIfNeeded();
        await page.locator('#decision').selectOption('approved');
        await page
            .locator('#comment')
            .fill(
                'Scope and preflight checks match the approved maintenance plan.',
            );
        await screenshot(page, 'reviewer-decision.png');

        await visit(page, '/query-requests');
        const approvedHref = await page
            .getByRole('link', {
                name: 'DEP-2041: Mark imported orders reviewed',
                exact: true,
            })
            .getAttribute('href');

        if (!approvedHref) {
            throw new Error('The approved documentation request is missing.');
        }

        await visit(page, new URL(approvedHref, baseUrl).pathname);
        await screenshot(page, 'approved-request.png');
    } finally {
        await context.close();
    }
}

async function captureAdministratorPages() {
    const { context, page } = await authenticatedPage(accounts.admin);

    try {
        await captureRoutes(page, [
            ['/dashboard', 'admin-navigation.png'],
            ['/connections', 'admin-connections.png'],
            [
                '/settings/admin/connection-groups',
                'admin-connection-groups.png',
            ],
            ['/settings/admin/users', 'admin-people.png'],
            ['/settings/admin/roles', 'admin-access-roles.png'],
            ['/settings/admin/authentication', 'admin-authentication.png'],
            ['/settings/admin/application', 'admin-application.png'],
            ['/settings/admin/notifications', 'admin-notification-policy.png'],
            ['/settings/admin/sql-policy', 'admin-sql-policy.png'],
            ['/settings/admin/audit-logs', 'admin-audit-log.png'],
            [
                '/settings/admin/authentication-providers',
                'admin-authentication-providers.png',
            ],
            ['/settings/admin/users/create', 'admin-invite-user.png'],
            ['/connections/create', 'admin-new-connection.png'],
            ['/horizon', 'operator-horizon.png'],
        ]);

        await visit(page, '/settings/admin/connection-groups');
        const groupHref = await page
            .getByRole('link', { name: 'Edit Customer-facing services' })
            .getAttribute('href');

        if (!groupHref) {
            throw new Error('The documentation connection group is missing.');
        }

        await visit(page, new URL(groupHref, baseUrl).pathname);
        await screenshot(page, 'admin-connection-group-membership.png');

        await visit(page, '/settings/admin/application');
        await page
            .getByText('Factory reset', { exact: true })
            .first()
            .scrollIntoViewIfNeeded();
        await screenshot(page, 'admin-factory-reset.png');

        await visit(page, '/settings/admin/sql-policy');
        await page
            .getByRole('heading', { name: 'Emergency SQL fallback' })
            .scrollIntoViewIfNeeded();
        await screenshot(page, 'admin-emergency-fallback.png');

        await visit(page, '/settings/admin/roles');
        const roleHref = await page
            .getByRole('link', { name: 'Edit Developer' })
            .getAttribute('href');

        if (!roleHref) {
            throw new Error('The documentation requester role is missing.');
        }

        await visit(page, new URL(roleHref, baseUrl).pathname);
        await page
            .getByRole('heading', { name: 'Connection groups' })
            .scrollIntoViewIfNeeded();
        await screenshot(page, 'admin-role-policy.png');
    } finally {
        await context.close();
    }
}

async function authenticatedPage(email) {
    const result = await createPage();
    const { context, page } = result;

    try {
        await visit(page, '/login');
        await page.locator('input[name=email]').fill(email);
        await page.locator('input[name=password]').fill(password);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/dashboard/, { timeout: 15_000 });
        await page.waitForLoadState('domcontentloaded');

        return result;
    } catch (error) {
        await context.close();
        throw error;
    }
}

async function captureAdministratorDetailPages() {
    const { context, page } = await authenticatedPage(accounts.admin);

    try {
        await visit(page, '/settings/admin/application');
        await page
            .getByText('Factory reset', { exact: true })
            .first()
            .scrollIntoViewIfNeeded();
        await screenshot(page, 'admin-factory-reset.png');

        await visit(page, '/settings/admin/sql-policy');
        await page
            .getByRole('heading', { name: 'Emergency SQL fallback' })
            .scrollIntoViewIfNeeded();
        await screenshot(page, 'admin-emergency-fallback.png');
    } finally {
        await context.close();
    }
}

async function captureTwoFactorChallenge() {
    const { context, page } = await createPage();

    try {
        await visit(page, '/login');
        await page.locator('input[name=email]').fill(accounts.twoFactor);
        await page.locator('input[name=password]').fill(password);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/two-factor-challenge/, { timeout: 15_000 });
        await screenshot(page, 'two-factor-challenge.png', true);
    } finally {
        await context.close();
    }
}

async function createPage() {
    const context = await browser.newContext({
        colorScheme: 'light',
        reducedMotion: 'reduce',
        viewport,
    });
    const page = await context.newPage();

    return { context, page };
}

async function captureRoutes(page, routes) {
    for (const [path, filename] of routes) {
        await visit(page, path);
        await screenshot(page, filename);
    }
}

async function captureRequest(page, title, filename) {
    await visit(page, '/query-requests');
    await visitLinkedPage(page, title);
    await screenshot(page, filename);
}

async function visitLinkedPage(page, name) {
    const href = await page
        .getByRole('link', { name, exact: true })
        .getAttribute('href');

    if (!href) {
        throw new Error(`The documentation link "${name}" is missing.`);
    }

    await visit(page, new URL(href, baseUrl).pathname);
}

async function visit(page, path) {
    const url = path.startsWith('http') ? path : `${baseUrl}${path}`;
    const response = await page.goto(url, {
        waitUntil: 'domcontentloaded',
        timeout: 15_000,
    });

    if (!response || response.status() >= 400) {
        throw new Error(
            `Unable to capture ${path}: HTTP ${response?.status() ?? 'unknown'}.`,
        );
    }

    await page.waitForTimeout(450);
}

async function screenshot(page, filename, fullPage = false) {
    await page.evaluate(() => {
        if (document.activeElement instanceof HTMLElement) {
            document.activeElement.blur();
        }
    });
    await page.screenshot({
        path: resolve(outputDirectory, filename),
        animations: 'disabled',
        caret: 'hide',
        fullPage,
        scale: 'css',
    });
}
