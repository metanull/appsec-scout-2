<?php

namespace Database\Factories;

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityEvent>
 */
class SecurityEventFactory extends Factory
{
    protected $model = SecurityEvent::class;

    public function definition(): array
    {
        return [
            'source_id' => $this->faker->randomElement(['azdo', 'asoc', 'detectify']),
            'source_event_id' => (string) $this->faker->unique()->numberBetween(1000, 99999),
            'software_system_id' => SoftwareSystem::factory(),
            'container_id' => null,
            'title' => $this->faker->sentence(6),
            'description' => $this->faker->optional()->paragraph(),
            'severity' => $this->faker->randomElement(EventSeverity::cases()),
            'state' => EventState::Open,
            'type' => $this->faker->randomElement(EventType::cases()),
            'rule_id' => $this->faker->optional()->regexify('[A-Z]+-[0-9]{3,5}'),
            'fingerprint' => $this->faker->sha1(),
            'url' => $this->faker->optional()->url(),
            'remediation' => null,
            'file_path' => null,
            'start_line' => null,
            'end_line' => null,
            'snippet' => null,
            'commit_sha' => null,
            'branch' => null,
            'version_control_url' => null,
            'source_data' => null,
            'metadata' => null,
            'first_seen_at' => $this->faker->dateTimeBetween('-1 year', '-1 month'),
            'last_seen_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'synced_at' => now(),
            'updated_at' => now(),
            'is_dirty' => false,
            'pending_state' => null,
            'pending_comment' => null,
        ];
    }

    public function vulnerability(): static
    {
        return $this->state([
            'type' => EventType::Vulnerability,
            'severity' => EventSeverity::High,
            'state' => EventState::Open,
            'file_path' => 'src/db/queries.php',
            'start_line' => 42,
            'end_line' => 45,
            'snippet' => '$query = "SELECT * FROM users WHERE id = \'" . $userId . "\'";',
            'commit_sha' => 'def789abc012',
            'branch' => 'main',
        ]);
    }

    public function secret(): static
    {
        return $this->state([
            'type' => EventType::Secret,
            'severity' => EventSeverity::Critical,
            'state' => EventState::Open,
            'file_path' => 'src/config/secrets.php',
            'start_line' => 15,
            'metadata' => ['truncatedSecret' => 'ghp_xxxx', 'detector' => 'GitHub-PAT'],
        ]);
    }

    public function dependency(): static
    {
        return $this->state([
            'type' => EventType::Dependency,
            'severity' => EventSeverity::High,
            'state' => EventState::Open,
            'metadata' => ['package' => ['name' => 'lodash', 'version' => '4.17.19', 'ecosystem' => 'npm'], 'cve' => 'CVE-2020-8203'],
        ]);
    }

    public function forSystem(SoftwareSystem $system): static
    {
        return $this->state(['software_system_id' => $system->id]);
    }

    public function forContainer(SecurityContainer $container): static
    {
        return $this->state([
            'software_system_id' => $container->software_system_id,
            'container_id' => $container->id,
        ]);
    }

    public function dirty(): static
    {
        return $this->state([
            'is_dirty' => true,
            'pending_state' => EventState::Resolved,
            'pending_comment' => 'Fixed in latest release.',
        ]);
    }
}
