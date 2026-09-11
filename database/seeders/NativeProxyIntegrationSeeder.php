<?php

namespace Database\Seeders;

use App\Enums\AccessMode;
use App\Enums\AccessTransport;
use App\Enums\DatabaseDriver;
use App\Enums\DatabaseTlsMode;
use App\Enums\NativeProxyDeviceAuthorizationStatus;
use App\Enums\NativeProxyLeaseStatus;
use App\Enums\PreflightStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Models\DatabaseConnection;
use App\Models\NativeProxyConnection;
use App\Models\NativeProxyDeviceAuthorization;
use App\Models\NativeProxyLease;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use LogicException;

class NativeProxyIntegrationSeeder extends Seeder
{
    use WithoutModelEvents;

    public const string PostgreSqlLeaseId = '01ARZ3NDEKTSV4RRFFQ69G5FA1';

    public const string MySqlLeaseId = '01ARZ3NDEKTSV4RRFFQ69G5FA2';

    public const string PostgreSqlWriteLeaseId = '01ARZ3NDEKTSV4RRFFQ69G5FA3';

    public const string MySqlWriteLeaseId = '01ARZ3NDEKTSV4RRFFQ69G5FA4';

    public const string PostgreSqlDeviceCode = 'native-integration-postgresql-device-code-0001';

    public const string MySqlDeviceCode = 'native-integration-mysql-device-code-00000002';

    public const string PostgreSqlWriteDeviceCode = 'native-integration-postgresql-write-device-0003';

    public const string MySqlWriteDeviceCode = 'native-integration-mysql-write-device-code-0004';

    public const string SyntheticPassword = 'crucible-native-integration-password';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Native proxy integration fixtures may only be seeded locally or during tests.');
        }

        $this->call(DatabaseSeeder::class);

        $user = User::query()->where('email', 'developer@example.com')->firstOrFail();
        $role = $user->roles()->firstOrFail();

        $this->fixture(
            user: $user,
            roleId: $role->id,
            connection: DatabaseConnection::query()->where('driver', DatabaseDriver::PostgreSql)->firstOrFail(),
            leaseId: self::PostgreSqlLeaseId,
            deviceCode: self::PostgreSqlDeviceCode,
            syntheticUsername: 'crucible_native_postgresql',
            accessMode: AccessMode::Read,
        );
        $this->fixture(
            user: $user,
            roleId: $role->id,
            connection: DatabaseConnection::query()->where('driver', DatabaseDriver::MySql)->firstOrFail(),
            leaseId: self::MySqlLeaseId,
            deviceCode: self::MySqlDeviceCode,
            syntheticUsername: 'crucible_native_mysql',
            accessMode: AccessMode::Read,
        );
        $this->fixture(
            user: $user,
            roleId: $role->id,
            connection: DatabaseConnection::query()->where('driver', DatabaseDriver::PostgreSql)->firstOrFail(),
            leaseId: self::PostgreSqlWriteLeaseId,
            deviceCode: self::PostgreSqlWriteDeviceCode,
            syntheticUsername: 'crucible_native_postgresql_write',
            accessMode: AccessMode::Write,
        );
        $this->fixture(
            user: $user,
            roleId: $role->id,
            connection: DatabaseConnection::query()->where('driver', DatabaseDriver::MySql)->firstOrFail(),
            leaseId: self::MySqlWriteLeaseId,
            deviceCode: self::MySqlWriteDeviceCode,
            syntheticUsername: 'crucible_native_mysql_write',
            accessMode: AccessMode::Write,
        );
    }

    private function fixture(User $user, int $roleId, DatabaseConnection $connection, string $leaseId, string $deviceCode, string $syntheticUsername, AccessMode $accessMode): void
    {
        $connection->forceFill(['tls_mode' => DatabaseTlsMode::Disabled, 'is_active' => true])->save();
        RoleDatabasePermission::query()->updateOrCreate(
            ['role_id' => $roleId, 'database_connection_id' => $connection->id],
            [
                'access_mode' => $accessMode,
                'query_access_mode' => AccessMode::Read,
                'native_proxy_access_mode' => $accessMode,
                'can_review' => false,
                'requires_approval' => false,
                'read_requires_approval' => false,
                'write_requires_approval' => false,
                'max_write_session_minutes' => $accessMode === AccessMode::Write ? 60 : null,
            ],
        );

        $request = QueryRequest::query()->updateOrCreate(
            ['title' => 'Native proxy integration '.$connection->driver->value.' '.$accessMode->value],
            [
                'requester_id' => $user->id,
                'database_connection_id' => $connection->id,
                'description' => 'Automated same-origin native proxy integration fixture.',
                'sql' => '',
                'query_type' => QueryType::Read,
                'request_kind' => QueryRequestKind::QueryAccess,
                'access_transport' => AccessTransport::NativeProxy,
                'requested_access_mode' => $accessMode,
                'status' => QueryRequestStatus::Approved,
                'requires_approval' => false,
                'preflight_status' => PreflightStatus::NotRun,
                'access_duration_minutes' => 60,
                'approved_at' => now(),
            ],
        );
        $request->accessConnections()->sync([$connection->id]);

        $session = QuerySession::query()->updateOrCreate(
            ['query_request_id' => $request->id, 'user_id' => $user->id],
            [
                'database_connection_id' => $connection->id,
                'started_at' => now(),
                'expires_at' => now()->addHour(),
                'ended_at' => null,
            ],
        );
        $session->databaseConnections()->sync([$connection->id]);

        $lease = NativeProxyLease::query()->updateOrCreate(
            ['id' => $leaseId],
            [
                'query_session_id' => $session->id,
                'query_request_id' => $request->id,
                'user_id' => $user->id,
                'database_connection_id' => $connection->id,
                'protocol' => $connection->driver,
                'access_mode' => $accessMode,
                'synthetic_username' => $syntheticUsername,
                'synthetic_password_hash' => Hash::make(self::SyntheticPassword),
                'protocol_auth_secret' => self::SyntheticPassword,
                'credential_version' => 1,
                'status' => NativeProxyLeaseStatus::Active,
                'max_concurrent_connections' => 3,
                'credentials_revealed_at' => now(),
                'activated_at' => now(),
                'expires_at' => $session->expires_at,
                'last_used_at' => null,
                'revoked_at' => null,
                'revocation_reason' => null,
                'revoked_by_id' => null,
            ],
        );
        NativeProxyConnection::query()->where('lease_id', $lease->id)->delete();
        $lease->tokens()->delete();
        $lease->deviceAuthorizations()->delete();
        NativeProxyDeviceAuthorization::query()->create([
            'lease_id' => $lease->id,
            'device_code_hash' => hash('sha256', $deviceCode),
            'user_code_hash' => hash('sha256', 'E2E-'.$connection->driver->value.'-'.$accessMode->value),
            'cli_version' => 'integration',
            'operating_system' => PHP_OS_FAMILY,
            'architecture' => php_uname('m'),
            'device_label' => 'CI qualification harness',
            'polling_interval_seconds' => 1,
            'status' => NativeProxyDeviceAuthorizationStatus::Approved,
            'expires_at' => now()->addMinutes(5),
            'approved_at' => now(),
            'authorized_by_id' => $user->id,
        ]);
    }
}
