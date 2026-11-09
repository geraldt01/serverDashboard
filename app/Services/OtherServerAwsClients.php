<?php

namespace App\Services;

use App\Models\OtherServer;
use Aws\Credentials\AssumeRoleCredentialProvider;
use Aws\Ssm\SsmClient;
use Aws\Sts\StsClient;

class OtherServerAwsClients
{
    public function arguments(OtherServer $server, ?string $region = null): array
    {
        $args = ['version' => 'latest', 'region' => $region ?? config('services.aws.region')];
        $roleArn = trim((string) $server->aws_role_arn);

        if ($roleArn !== '') {
            try {
                $provider = new AssumeRoleCredentialProvider([
                    'client' => app(StsClient::class, ['args' => $args]),
                    'assume_role_params' => [
                        'RoleArn' => $roleArn,
                        'RoleSessionName' => 'serverdashboard-' . $server->id,
                        'DurationSeconds' => 900,
                    ],
                ]);
                $args['credentials'] = $provider()->wait();
            } catch (\Throwable $exception) {
                throw new \RuntimeException("Unable to assume AWS role {$roleArn}. Verify the dashboard's sts:AssumeRole permission and the target role's trust policy.", 0, $exception);
            }
        }

        return $args;
    }

    public function ssm(OtherServer $server): SsmClient
    {
        return app(SsmClient::class, ['args' => $this->arguments($server)]);
    }
}