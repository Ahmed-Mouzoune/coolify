<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use App\Models\Server;
use App\Traits\ExecuteRemoteCommand;
use Tests\TestCase;

uses(TestCase::class);

function cancelledDeploymentCommandRunner(): object
{
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('isNonRoot')->andReturnFalse();

    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->status = ApplicationDeploymentStatus::CANCELLED_BY_USER->value;
    $queue->shouldReceive('refresh')->andReturnSelf();

    return new class($server, $queue)
    {
        use ExecuteRemoteCommand;

        public array $executed = [];

        public function __construct(public Server $server, public ApplicationDeploymentQueue $application_deployment_queue) {}

        private function executeCommandWithProcess($command, $hidden, $customType, $append, $ignore_errors, $command_hidden = false, $skip_command_log = false, ?string $input = null)
        {
            $this->executed[] = (string) $command;
        }
    };
}

it('refuses to run commands once the deployment is cancelled', function () {
    $runner = cancelledDeploymentCommandRunner();

    expect(fn () => $runner->execute_remote_command(['docker build .']))
        ->toThrow(RuntimeException::class, 'Deployment cancelled by user');
    expect($runner->executed)->toBeEmpty();
});

it('still runs cleanup commands that skip the cancellation check', function () {
    $runner = cancelledDeploymentCommandRunner();

    $runner->execute_remote_command(['docker stop deployment-uuid', 'hidden' => true, 'ignore_errors' => true, 'skip_cancellation_check' => true]);

    expect($runner->executed)->toBe(['docker stop deployment-uuid']);
});

it('stops the helper container of a cancelled deployment', function (bool $evenIfCancelled) {
    $settings = new ApplicationSetting;
    $settings->setRawAttributes(['stop_grace_period' => 30]);
    $application = new Application;
    $application->setRelation('settings', $settings);

    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $commands = [];
    $job->shouldReceive('execute_remote_command')->andReturnUsing(function (...$sent) use (&$commands) {
        array_push($commands, ...$sent);
    });

    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $reflection->getProperty('application')->setValue($job, $application);
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('dockerVersion')->andReturn('29.0.0');
    $reflection->getProperty('server')->setValue($job, $server);
    $reflection->getMethod('graceful_shutdown_container')->invoke($job, 'deployment-uuid', true, $evenIfCancelled);

    expect($commands)->toHaveCount(1)
        ->and($commands[0][0])->toContain('docker stop')
        ->and($commands[0]['skip_cancellation_check'])->toBe($evenIfCancelled);
})->with([
    'cleanup after a cancellation' => [true],
    'normal shutdown' => [false],
]);
