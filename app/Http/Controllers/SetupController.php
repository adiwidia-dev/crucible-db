<?php

namespace App\Http\Controllers;

use App\Enums\DatabaseDriver;
use App\Http\Requests\CompleteInitialSetupRequest;
use App\Http\Requests\StoreInitialConnectionRequest;
use App\Models\DatabaseConnection;
use App\Models\Role;
use App\Models\User;
use App\Services\ApplicationSettings;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    public function show(): Response
    {
        abort_if(User::query()->exists(), 404);

        return Inertia::render('setup/owner', [
            'app_name' => config('app.name'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(CompleteInitialSetupRequest $request, ApplicationSettings $settings, AuditLogger $auditLogger): RedirectResponse
    {
        $user = DB::transaction(function () use ($request, $settings): User {
            abort_if(User::query()->exists(), 409);

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
            ]);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
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
