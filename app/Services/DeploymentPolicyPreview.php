<?php

namespace App\Services;

use App\Models\DatabaseConnection;
use Illuminate\Validation\ValidationException;

class DeploymentPolicyPreview
{
    public function __construct(private readonly DeploymentStatementPolicy $policy) {}

    /**
     * @return array{sql:string,database_connection_id:int,message:string|null,reviewable:bool,source:string|null}
     */
    public function forStatement(string $sql, DatabaseConnection $connection): array
    {
        $preview = [
            'sql' => $sql,
            'database_connection_id' => $connection->id,
            'message' => null,
            'reviewable' => false,
            'source' => null,
        ];

        try {
            $assessment = $this->policy->assess($sql, $connection);
            $preview['source'] = $assessment['source'];

            if ($assessment['source'] === 'unsupported') {
                $preview['message'] = 'This SQL statement is not supported by the governed SQL policy.';
                $preview['reviewable'] = true;
            }
        } catch (ValidationException $exception) {
            $preview['message'] = collect($exception->errors())->flatten()->first() ?? 'This SQL statement is blocked by the governed SQL policy.';
        }

        return $preview;
    }
}
