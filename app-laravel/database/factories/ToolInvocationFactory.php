<?php

namespace Database\Factories;

use App\Models\SecurityContainer;
use App\Models\StaticAnalysisRun;
use App\Models\ToolInvocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ToolInvocation>
 */
class ToolInvocationFactory extends Factory
{
    protected $model = ToolInvocation::class;

    public function definition(): array
    {
        $startedAt = $this->faker->dateTimeBetween('-1 week', '-1 hour');

        return [
            // run_id has no foreign-key constraint (see the migration's own
            // comment) so an arbitrary integer is valid here; pass a real
            // run's id explicitly in tests that need one to resolve.
            'run_type' => StaticAnalysisRun::class,
            'run_id' => $this->faker->numberBetween(1, 1000),
            'security_container_id' => SecurityContainer::factory(),
            'tool' => $this->faker->randomElement(['opengrep', 'dotnet-roslynator', 'java-spotbugs']),
            'tool_version' => null,
            'command_json' => null,
            'outcome' => 'ran_clean',
            'exit_code' => 0,
            'started_at' => $startedAt,
            'finished_at' => (clone $startedAt)->modify('+30 seconds'),
            'duration_seconds' => 30,
            'output_tail' => null,
        ];
    }
}
