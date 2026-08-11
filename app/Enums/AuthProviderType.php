<?php

namespace App\Enums;

enum AuthProviderType: string
{
    case Google = 'google';
    case GitHub = 'github';
    case Microsoft = 'microsoft';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::GitHub => 'GitHub',
            self::Microsoft => 'Microsoft',
        };
    }

    /**
     * @return array<int, string>
     */
    public function defaultScopes(): array
    {
        return match ($this) {
            self::Google => ['openid', 'profile', 'email'],
            self::GitHub => ['read:user', 'user:email'],
            self::Microsoft => ['openid', 'profile', 'User.Read'],
        };
    }

    /**
     * @return array{overview: string, steps: array<int, string>, documentation_url: string, documentation_label: string}
     */
    public function setupGuide(): array
    {
        return match ($this) {
            self::Google => [
                'overview' => 'Create a web OAuth 2.0 client in Google Cloud, configure the consent screen, and register Crucible DB’s callback URL after saving this provider.',
                'steps' => [
                    'Open Google Cloud Console and select the project that owns this integration.',
                    'Configure the OAuth consent screen, then create an OAuth client ID for a web application.',
                    'After saving this provider, add its callback URL as an authorized redirect URI.',
                    'Copy the client ID and client secret here. Keep the default openid, profile, and email scopes.',
                ],
                'documentation_url' => 'https://developers.google.com/identity/protocols/oauth2/web-server',
                'documentation_label' => 'Google OAuth web application guide',
            ],
            self::GitHub => [
                'overview' => 'Register a GitHub OAuth App and use the callback URL shown after this provider is saved.',
                'steps' => [
                    'Open GitHub Settings, Developer settings, then OAuth Apps.',
                    'Create a new OAuth App with this Crucible DB instance as the homepage URL.',
                    'After saving this provider, set its callback URL as the Authorization callback URL.',
                    'Generate a client secret and copy both the client ID and secret here. Keep scopes limited to profile and email access.',
                ],
                'documentation_url' => 'https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/creating-an-oauth-app',
                'documentation_label' => 'GitHub OAuth App guide',
            ],
            self::Microsoft => [
                'overview' => 'Register a server-side web application in Microsoft Entra ID, then use its client ID, client-secret value, and callback URL here.',
                'steps' => [
                    'Open Microsoft Entra admin center and create a new app registration.',
                    'Under Authentication, add a Web platform and the callback URL shown after saving this provider.',
                    'Create a client secret under Certificates & secrets and copy the secret value immediately.',
                    'Copy the Application (client) ID here. Use common for a multi-tenant app or enter your tenant ID to limit sign-in.',
                ],
                'documentation_url' => 'https://learn.microsoft.com/en-us/graph/auth-register-app-v2',
                'documentation_label' => 'Microsoft Entra web app registration guide',
            ],
        };
    }
}
