<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Enums\AccessTransport;
use App\Enums\DatabaseDriver;
use App\Enums\ExecutionStatus;
use App\Enums\NativeProxyConnectionStatus;
use App\Enums\NativeProxyDeviceAuthorizationStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Models\DatabaseConnection;
use App\Models\NativeProxyConnection;
use App\Models\NativeProxyDeviceAuthorization;
use App\Models\NativeProxyLease;
use App\Models\NativeProxyToken;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\Role;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use App\Services\NativeProxy\NativeStatementData;
use App\Services\NativeProxy\NativeStatementOutcome;
use App\Services\NativeProxy\StatementWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NativeProxyStatementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_validation_does_not_create_an_execution_but_authorized_execution_is_audited_without_rows(): void
    {
        $connection = $this->activeConnection();
        $workflow = app(StatementWorkflow::class);
        $data = new NativeStatementData('SELECT * FROM users', 'simple_query');

        $this->assertTrue($workflow->validateTemplate($connection, $data)->allowed);
        $this->assertDatabaseCount('query_session_queries', 0);

        $statement = $workflow->authorizeExecution($connection, $data);
        $workflow->complete($statement, new NativeStatementOutcome(true, rowCount: 2, durationMilliseconds: 3));

        $this->assertDatabaseHas('query_session_queries', [
            'id' => $statement->id,
            'status' => ExecutionStatus::Succeeded->value,
            'native_protocol_command' => 'simple_query',
            'native_parameter_count' => 0,
        ]);
        $this->assertDatabaseHas('query_executions', [
            'native_proxy_connection_id' => $connection->id,
            'status' => ExecutionStatus::Succeeded->value,
        ]);
    }

    public function test_allowed_native_session_command_is_authorized_and_audited_as_read_activity(): void
    {
        $connection = $this->activeConnection(DatabaseDriver::MySql);
        $workflow = app(StatementWorkflow::class);
        $data = new NativeStatementData('SHOW DATABASES', 'com_query');

        $decision = $workflow->validateTemplate($connection, $data);
        $this->assertTrue($decision->allowed);
        $this->assertTrue($decision->sessionCommand);
        $this->assertSame('lease_databases', $decision->resultFilter);

        $statement = $workflow->authorizeExecution($connection, $data);
        $workflow->complete($statement, new NativeStatementOutcome(true, rowCount: 1, durationMilliseconds: 3));

        $this->assertDatabaseHas('query_session_queries', [
            'id' => $statement->id,
            'query_type' => QueryType::Read->value,
            'status' => ExecutionStatus::Succeeded->value,
            'native_protocol_command' => 'com_query',
        ]);
        $this->assertDatabaseHas('query_executions', [
            'native_query_session_query_id' => $statement->id,
            'query_type' => QueryType::Read->value,
            'status' => ExecutionStatus::Succeeded->value,
        ]);
    }

    public function test_blocked_native_statement_creates_no_execution_record(): void
    {
        $connection = $this->activeConnection();

        try {
            app(StatementWorkflow::class)->authorizeExecution($connection, new NativeStatementData('SET ROLE administrator', 'simple_query'));
            $this->fail('Expected native statement authorization to fail.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('query_session_queries', 0);
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'native_proxy.statement_blocked',
                'auditable_id' => $connection->id,
            ]);
        }
    }

    public function test_audit_templates_and_errors_do_not_retain_dialect_literals(): void
    {
        $connection = $this->activeConnection();
        $workflow = app(StatementWorkflow::class);
        $sql = <<<'SQL'
SELECT $$private-dollar-value$$, E'private-escape-value', X'CAFE', B'1010', 0xDEADBEEF, 912345
SQL;

        $statement = $workflow->authorizeExecution($connection, new NativeStatementData($sql, 'simple_query'));
        $workflow->complete($statement, new NativeStatementOutcome(false, errorMessage: 'duplicate key contains private-error-value'));
        $statement->refresh();

        $this->assertStringNotContainsString('private-dollar-value', $statement->sql);
        $this->assertStringNotContainsString('private-escape-value', $statement->sql);
        $this->assertStringNotContainsString('CAFE', $statement->sql);
        $this->assertStringNotContainsString('DEADBEEF', $statement->sql);
        $this->assertSame('Native database statement failed.', $statement->error_message);
        $this->assertDatabaseMissing('query_executions', ['error_message' => 'duplicate key contains private-error-value']);
    }

    private function activeConnection(DatabaseDriver $driver = DatabaseDriver::PostgreSql): NativeProxyConnection
    {
        $databaseConnection = match ($driver) {
            DatabaseDriver::MySql => DatabaseConnection::factory()->mysql()->create(),
            DatabaseDriver::PostgreSql => DatabaseConnection::factory()->postgresql()->create(),
        };
        $role = Role::factory()->developer()->create();
        $user = User::factory()->withRole($role)->create();
        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $databaseConnection->id,
            'access_mode' => AccessMode::Read,
            'native_proxy_access_mode' => AccessMode::Read,
        ]);
        $request = QueryRequest::factory()->queryAccess()->approved()->create([
            'requester_id' => $user->id,
            'database_connection_id' => $databaseConnection->id,
            'request_kind' => QueryRequestKind::QueryAccess,
            'access_transport' => AccessTransport::NativeProxy,
            'requested_access_mode' => AccessMode::Read,
            'status' => QueryRequestStatus::Approved,
        ]);
        $session = QuerySession::factory()->create([
            'query_request_id' => $request->id,
            'user_id' => $user->id,
            'database_connection_id' => $databaseConnection->id,
        ]);
        $lease = NativeProxyLease::factory()->active()->create([
            'query_session_id' => $session->id,
            'query_request_id' => $request->id,
            'user_id' => $user->id,
            'database_connection_id' => $databaseConnection->id,
            'protocol' => $driver,
            'access_mode' => AccessMode::Read,
        ]);
        $device = NativeProxyDeviceAuthorization::factory()->for($lease, 'lease')->create([
            'status' => NativeProxyDeviceAuthorizationStatus::Consumed,
        ]);
        NativeProxyToken::factory()->for($lease, 'lease')->for($device, 'deviceAuthorization')->active()->create();

        return NativeProxyConnection::factory()->active()->create([
            'lease_id' => $lease->id,
            'query_session_id' => $session->id,
            'query_request_id' => $request->id,
            'user_id' => $user->id,
            'database_connection_id' => $databaseConnection->id,
            'protocol' => $driver,
            'status' => NativeProxyConnectionStatus::Active,
        ]);
    }
}
