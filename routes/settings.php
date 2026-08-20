<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthProviderController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\Settings\ApplicationSettingsController;
use App\Http\Controllers\Settings\AuthenticationMethodController;
use App\Http\Controllers\Settings\FactoryResetController;
use App\Http\Controllers\Settings\NotificationPreferencesController;
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\SsoController;
use App\Http\Controllers\UserInvitationController;
use App\Http\Controllers\UserRoleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
    Route::get('settings/notifications', [NotificationPreferencesController::class, 'edit'])->name('user-notifications.edit');
    Route::patch('settings/notifications', [NotificationPreferencesController::class, 'update'])->name('user-notifications.update');

    Route::prefix('settings/admin')->group(function (): void {
        Route::get('/', fn () => redirect()->route('application-settings.edit'))->name('admin-settings.index');
        Route::get('application', [ApplicationSettingsController::class, 'edit'])->name('application-settings.edit');
        Route::patch('application', [ApplicationSettingsController::class, 'update'])->name('application-settings.update');
        Route::get('notifications', [NotificationSettingsController::class, 'edit'])->name('notification-settings.edit');
        Route::patch('notifications', [NotificationSettingsController::class, 'update'])->name('notification-settings.update');
        Route::delete('application/factory-reset', FactoryResetController::class)->name('application-settings.factory-reset');
        Route::get('authentication', [AuthenticationMethodController::class, 'edit'])->name('authentication-methods.edit');
        Route::patch('authentication', [AuthenticationMethodController::class, 'update'])->name('authentication-methods.update');

        Route::resource('authentication-providers', AuthProviderController::class)
            ->parameters(['authentication-providers' => 'auth_provider'])
            ->names('auth-providers')
            ->except(['show']);
        Route::get('authentication-providers/{auth_provider}/test', [SsoController::class, 'testRedirect'])
            ->name('auth-providers.test');

        Route::get('users', [UserRoleController::class, 'index'])->name('users.index');
        Route::get('users/create', [UserInvitationController::class, 'create'])->name('users.create');
        Route::post('users', [UserInvitationController::class, 'store'])->name('users.store');
        Route::patch('users/{user}/role', [UserRoleController::class, 'update'])->name('users.role.update');
        Route::patch('users/{user}/disable', [UserRoleController::class, 'disable'])->name('users.disable');
        Route::patch('users/{user}/enable', [UserRoleController::class, 'enable'])->name('users.enable');
        Route::resource('roles', RoleController::class);
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('audit-logs/export', [AuditLogController::class, 'export'])->name('audit-logs.export');
    });

    Route::redirect('auth-providers', '/settings/admin/authentication-providers');
    Route::redirect('settings/authentication-providers', '/settings/admin/authentication-providers');
    Route::redirect('users', '/settings/admin/users');
    Route::redirect('roles', '/settings/admin/roles');
    Route::redirect('audit-logs', '/settings/admin/audit-logs');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
