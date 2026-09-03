<?php

use App\Http\Controllers\Internal\NativeProxy\ConnectionController;
use App\Http\Controllers\Internal\NativeProxy\ConnectionHeartbeatController;
use App\Http\Controllers\Internal\NativeProxy\DeviceAuthorizationController;
use App\Http\Controllers\Internal\NativeProxy\LeaseHeartbeatController;
use App\Http\Controllers\Internal\NativeProxy\StatementController;
use App\Http\Controllers\Internal\NativeProxy\TunnelAuthorizationController;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/native-proxy/v1')
    ->middleware(['application-database-migration', 'native-proxy-control'])
    ->group(function (): void {
        Route::get('health', fn () => response()->json(['status' => 'ok']))
            ->name('internal.native-proxy.health');
        Route::post('device-authorizations', [DeviceAuthorizationController::class, 'store'])
            ->middleware('throttle:native-proxy-device-authorizations')
            ->name('internal.native-proxy.device-authorizations.store');
        Route::post('device-token', [DeviceAuthorizationController::class, 'token'])
            ->middleware('throttle:native-proxy-device-token')
            ->name('internal.native-proxy.device-token.store');
        Route::post('leases/heartbeat', LeaseHeartbeatController::class)
            ->name('internal.native-proxy.leases.heartbeat');
        Route::post('tunnels/authorize', TunnelAuthorizationController::class)
            ->name('internal.native-proxy.tunnels.authorize');
        Route::post('connections', [ConnectionController::class, 'store'])
            ->name('internal.native-proxy.connections.store');
        Route::post('connections/{nativeProxyConnection}/upstream', [ConnectionController::class, 'upstream'])
            ->name('internal.native-proxy.connections.upstream');
        Route::post('connections/{nativeProxyConnection}/authenticated', [ConnectionHeartbeatController::class, 'authenticated'])
            ->name('internal.native-proxy.connections.authenticated');
        Route::post('connections/{nativeProxyConnection}/heartbeat', [ConnectionHeartbeatController::class, 'heartbeat'])
            ->name('internal.native-proxy.connections.heartbeat');
        Route::delete('connections/{nativeProxyConnection}', [ConnectionHeartbeatController::class, 'destroy'])
            ->name('internal.native-proxy.connections.destroy');
        Route::post('connections/traffic', [ConnectionHeartbeatController::class, 'traffic'])
            ->name('internal.native-proxy.connections.traffic');
        Route::post('connections/{nativeProxyConnection}/statements/validate', [StatementController::class, 'validateTemplate'])
            ->name('internal.native-proxy.statements.validate');
        Route::post('connections/{nativeProxyConnection}/statements', [StatementController::class, 'authorizeExecution'])
            ->name('internal.native-proxy.statements.store');
        Route::post('connections/{nativeProxyConnection}/statements/{querySessionQuery}/complete', [StatementController::class, 'complete'])
            ->name('internal.native-proxy.statements.complete');
    });
