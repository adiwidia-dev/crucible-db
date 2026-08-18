<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmFactoryResetRequest;
use App\Http\Requests\UpdateApplicationSettingsRequest;
use App\Services\ApplicationSettings;
use App\Services\AuditLogger;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationSettingsController extends Controller
{
    public function edit(ApplicationSettings $settings): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return Inertia::render('settings/admin/application', [
            'settings' => $settings->formValues(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'factory_reset_confirmation_phrase' => ConfirmFactoryResetRequest::ConfirmationPhrase,
        ]);
    }

    public function update(UpdateApplicationSettingsRequest $request, ApplicationSettings $settings, AuditLogger $auditLogger): RedirectResponse
    {
        $before = $settings->formValues();
        $values = $request->validated();

        if (blank($values['mail_password'] ?? null)) {
            unset($values['mail_password']);
        }

        $settings->put($values);

        $auditLogger->log('application_settings.updated', $request->user(), null, [
            'before' => $before,
            'after' => $settings->formValues(),
            'mail_password_updated' => $request->filled('mail_password'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Application settings updated.']);

        return back();
    }
}
