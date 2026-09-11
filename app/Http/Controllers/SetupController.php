<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationDatabaseDriver;
use App\Enums\DatabaseDriver;
use App\Http\Requests\AuthorizeInitialSetupRequest;
use App\Http\Requests\CompleteInitialSetupRequest;
use App\Http\Requests\StoreApplicationDatabaseConfigurationRequest;
use App\Http\Requests\StoreInitialConnectionRequest;
use App\Models\DatabaseConnection;
use App\Models\Role;
use App\Models\User;
use App\Services\ApplicationDatabaseManager;
use App\Services\ApplicationSettings;
use App\Services\AuditLogger;
use App\Services\InitialSetupAccess;
use App\Services\InitialSetupState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    public function createAccess(InitialSetupAccess $initialSetupAccess, InitialSetupState $initialSetupState): Response
    {
        abort_unless($initialSetupState->canInitialize(), 404);
        abort_unless($initialSetupAccess->isConfigured(), 503, 'Initial setup is locked because CRUCIBLE_INITIAL_SETUP_TOKEN is not configured with at least 32 characters.');

        return Inertia::render('setup/access');
    }

    public function authorizeAccess(
        AuthorizeInitialSetupRequest $request,
        InitialSetupAccess $initialSetupAccess,
        InitialSetupState $initialSetupState,
        ApplicationDatabaseManager $applicationDatabaseManager,
    ): RedirectResponse {
        abort_unless($initialSetupState->canInitialize(), 404);

        if (! $initialSetupAccess->grant($request, $request->string('setup_token')->toString())) {
            throw ValidationException::withMessages([
                'setup_token' => 'The setup token is invalid.',
            ]);
        }

        $request->session()->regenerate();

        if ($applicationDatabaseManager->requiresRestart()) {
            return redirect()->route('setup.database.restart');
        }

        if ($applicationDatabaseManager->requiresSelection()) {
            return redirect()->route('setup.database.create');
        }

        return redirect()->route('setup.show');
    }

    public function show(ApplicationDatabaseManager $applicationDatabaseManager, InitialSetupState $initialSetupState): Response|RedirectResponse
    {
        abort_unless($initialSetupState->canInitialize(), 404);

        if ($applicationDatabaseManager->requiresRestart()) {
            return redirect()->route('setup.database.restart');
        }

        if ($applicationDatabaseManager->requiresSelection()) {
            return redirect()->route('setup.database.create');
        }

        return Inertia::render('setup/owner', [
            'app_name' => config('app.name'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function createApplicationDatabase(ApplicationDatabaseManager $applicationDatabaseManager, InitialSetupState $initialSetupState): Response|RedirectResponse
    {
        abort_unless($initialSetupState->canInitialize(), 404);

        if (! $applicationDatabaseManager->usesManagedConfiguration()) {
            return redirect()->route('setup.show');
        }

        if ($applicationDatabaseManager->requiresRestart()) {
            return redirect()->route('setup.database.restart');
        }

        if (! $applicationDatabaseManager->requiresSelection()) {
            return redirect()->route('setup.show');
        }

        return Inertia::render('setup/application-database', [
            'drivers' => array_map(fn (ApplicationDatabaseDriver $driver): array => [
                'value' => $driver->value,
                'label' => $driver->label(),
                'default_port' => $driver->defaultPort(),
            ], ApplicationDatabaseDriver::cases()),
            'sqlite_path' => config('database.connections.control.database'),
        ]);
    }

    public function storeApplicationDatabase(
        StoreApplicationDatabaseConfigurationRequest $request,
        ApplicationDatabaseManager $applicationDatabaseManager,
        InitialSetupState $initialSetupState,
    ): RedirectResponse {
        abort_unless($initialSetupState->canInitialize(), 409);
        $applicationDatabaseManager->provision($request->validated());

        return redirect()->route('setup.database.restart');
    }

    public function restartApplicationDatabase(ApplicationDatabaseManager $applicationDatabaseManager, InitialSetupState $initialSetupState): Response|RedirectResponse
    {
        abort_unless($initialSetupState->canInitialize(), 404);

        if (! $applicationDatabaseManager->requiresRestart()) {
            return redirect()->route('setup.show');
        }

        return Inertia::render('setup/database-restart');
    }

    public function store(
        CompleteInitialSetupRequest $request,
        ApplicationSettings $settings,
        AuditLogger $auditLogger,
        InitialSetupState $initialSetupState,
        InitialSetupAccess $initialSetupAccess,
    ): RedirectResponse {
        $user = Cache::lock('application:initial-setup', 15)->block(5, function () use ($request, $settings, $initialSetupState): User {
            return DB::transaction(function () use ($request, $settings, $initialSetupState): User {
                abort_unless($initialSetupState->canInitialize(), 409);

                $adminRole = Role::query()->firstOrCreate(
                    ['slug' => 'admin'],
                    [
                        'name' => 'Admin',
                        'description' => 'Can manage Crucible DB and review every request.',
                        'is_admin' => true,
                    ],
                );

                $user = User::query()->create([
                    'role_id' => $adminRole->id,
                    'first_name' => $request->string('first_name')->toString(),
                    'last_name' => $request->string('last_name')->toString() ?: null,
                    'name' => trim($request->string('first_name')->toString().' '.$request->string('last_name')->toString()),
                    'email' => $request->string('email')->lower()->toString(),
                    'password' => $request->string('password')->toString(),
                    'email_verified_at' => now(),
                    'timezone' => 'UTC',
                ]);
                $user->roles()->attach($adminRole, ['priority' => 100]);
                $settings->put([
                    ApplicationSettings::AppName => $request->string('app_name')->toString(),
                    ApplicationSettings::DefaultTimezone => (string) config('app.timezone'),
                    ApplicationSettings::PasswordLoginEnabled => true,
                    ApplicationSettings::PasskeyLoginEnabled => true,
                    ApplicationSettings::InitialSetupCompleted => true,
                ]);

                return $user;
            });
        });

        Auth::login($user);
        $request->session()->regenerate();
        $initialSetupAccess->revoke($request);
        $request->session()->put('setup.owner_id', $user->id);
        $auditLogger->log('application.initialized', $user, $user, ['app_name' => $settings->appName()]);

        return redirect()->route('setup.connection.create');
    }

    public function createConnection(): Response
    {
        $this->ensureInitialSetupOwner();

        return Inertia::render('setup/connection', [
            'drivers' => array_map(fn (DatabaseDriver $driver): array => [
                'value' => $driver->value,
                'label' => $driver === DatabaseDriver::MySql ? 'MySQL' : 'PostgreSQL',
                'default_port' => $driver->defaultPort(),
            ], DatabaseDriver::cases()),
        ]);
    }

    public function storeConnection(StoreInitialConnectionRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $connection = DatabaseConnection::query()->create([
            ...$request->validated(),
            'created_by_id' => $request->user()->id,
            'is_active' => true,
        ]);
        $request->session()->forget('setup.owner_id');
        $auditLogger->log('database_connection.created_during_setup', $request->user(), $connection);

        return redirect()->route('dashboard');
    }

    public function skipConnection(): RedirectResponse
    {
        $this->ensureInitialSetupOwner();
        request()->session()->forget('setup.owner_id');

        return redirect()->route('dashboard');
    }

    private function ensureInitialSetupOwner(): void
    {
        $user = request()->user();

        abort_unless($user->isAdmin(), 403);
        abort_unless(request()->session()->get('setup.owner_id') === $user->id, 404);
    }
}
