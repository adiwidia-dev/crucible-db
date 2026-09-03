<?php

namespace Tests\Unit;

use App\Enums\AccessMode;
use App\Enums\DatabaseDriver;
use App\Services\NativeProxy\ProxyStatementPolicy;
use Tests\TestCase;

class NativeProxyStatementPolicyTest extends TestCase
{
    public function test_postgresql_allows_safe_session_commands_and_blocks_privilege_changes(): void
    {
        $policy = app(ProxyStatementPolicy::class);

        $this->assertTrue($policy->decide(DatabaseDriver::PostgreSql, AccessMode::Read, 'app', 'SET LOCAL application_name = app_client')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::PostgreSql, AccessMode::Read, 'app', 'SET DateStyle=ISO')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::PostgreSql, AccessMode::Read, 'app', 'SET client_min_messages=notice')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::PostgreSql, AccessMode::Read, 'app', "SELECT set_config('bytea_output','hex',false) FROM pg_show_all_settings() WHERE name = 'bytea_output'")->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::PostgreSql, AccessMode::Read, 'app', "SET client_encoding='UTF8'")->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::PostgreSql, AccessMode::Read, 'app', 'SET standard_conforming_strings=on')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::PostgreSql, AccessMode::Read, 'app', 'ROLLBACK TO SAVEPOINT work')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::PostgreSql, AccessMode::Read, 'app', 'SET standard_conforming_strings=off')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::PostgreSql, AccessMode::Read, 'app', 'SET ROLE administrator')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::PostgreSql, AccessMode::Read, 'app', 'BEGIN READ WRITE')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::PostgreSql, AccessMode::Read, 'app', 'SHOW ALL')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::PostgreSql, AccessMode::Read, 'app', 'SHOW default_transaction_read_only')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::PostgreSql, AccessMode::Read, 'app', 'SET DateStyle=ISO; SET client_min_messages=notice')->allowed);
    }

    public function test_mysql_allows_safe_session_commands_and_blocks_cross_database_or_global_changes(): void
    {
        $policy = app(ProxyStatementPolicy::class);

        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'USE app')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'crucible_target', 'use crucible_target')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW DATABASES')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW TABLES')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW FULL TABLES FROM `app`')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW TABLE STATUS FROM app')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW TRIGGERS FROM app')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', "SHOW TRIGGERS FROM `app` WHERE `Table` = 'users'")->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW EVENTS FROM app')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW FULL COLUMNS FROM users FROM app')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW INDEX FROM app.users')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW CREATE TABLE app.users')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SET autocommit=1')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', '/* ApplicationName=DBeaver */ SET autocommit=0')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'crucible_target', '/* ApplicationName=DBeaver */ USE `crucible_target`')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', '/* client=DBeaver */ /* java thread=main */ SET autocommit=1')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SET CHARACTER SET utf8')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SET NAMES utf8')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', "SET NAMES 'utf8mb4'")->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SET SQL_SAFE_UPDATES=1')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SET @@SESSION.wait_timeout=610')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SET @@SESSION.interactive_timeout=610')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', "SHOW CHARACTER SET WHERE charset = 'utf8mb4'")->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW CHARSET')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', "SHOW SESSION STATUS LIKE 'Ssl_cipher'")->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW PLUGINS')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW VARIABLES')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW SESSION VARIABLES')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW LOCAL VARIABLES')->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', "SHOW VARIABLES LIKE 'lower_case_table_names'")->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', "SHOW SESSION VARIABLES LIKE 'version'")->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', "SHOW SESSION VARIABLES LIKE 'version_comment'")->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', "SHOW SESSION VARIABLES LIKE 'version_compile_os'")->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', "SHOW SESSION VARIABLES LIKE 'interactive_timeout'")->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', "SHOW SESSION VARIABLES LIKE 'offline_mode'")->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', "SHOW PROCEDURE STATUS WHERE Db='app'")->allowed);
        $this->assertTrue($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW FUNCTION STATUS WHERE `Db` = "app"')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'USE other')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW TABLES FROM other')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW TRIGGERS FROM other')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW EVENTS FROM other')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW FULL COLUMNS FROM users FROM other')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW INDEX FROM other.users')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SET GLOBAL sql_mode = ANSI')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', '/* ApplicationName=DBeaver */ SET GLOBAL sql_mode = ANSI')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', '/*! SET autocommit=1 */')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', '/*+ SET_VAR(foreign_key_checks=OFF) */ SET autocommit=1')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', '/* ApplicationName=DBeaver */ SET autocommit=1; DROP TABLE users')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SET CHARACTER SET latin1')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SET SQL_SAFE_UPDATES=0')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SET @@SESSION.wait_timeout=901')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW GRANTS')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW PROCESSLIST')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW GLOBAL VARIABLES')->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', "SHOW PROCEDURE STATUS WHERE Db='other'")->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', "SHOW VARIABLES LIKE 'local_infile'")->allowed);
        $this->assertFalse($policy->decide(DatabaseDriver::MySql, AccessMode::Read, 'app', 'SHOW REPLICA STATUS')->allowed);
    }
}
