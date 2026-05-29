<?php

namespace App\Trackers;

use App\Models\TrackerConfig;
use Illuminate\Support\Facades\Schema;

final class TrackerConfigRepository
{
    private const JIRA_DEFAULT_PROJECT_KEY = 'jira.default_project_key';

    public function getJiraDefaultProjectKey(): ?string
    {
        if (! $this->tableExists()) {
            return null;
        }

        $value = TrackerConfig::query()
            ->where('key', self::JIRA_DEFAULT_PROJECT_KEY)
            ->value('value');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    public function setJiraDefaultProjectKey(?string $projectKey): void
    {
        if (! $this->tableExists()) {
            return;
        }

        $normalized = is_string($projectKey) ? trim($projectKey) : '';

        if ($normalized === '') {
            TrackerConfig::query()->where('key', self::JIRA_DEFAULT_PROJECT_KEY)->delete();

            return;
        }

        TrackerConfig::query()->updateOrCreate(
            ['key' => self::JIRA_DEFAULT_PROJECT_KEY],
            ['value' => $normalized],
        );
    }

    private function tableExists(): bool
    {
        return Schema::hasTable('tracker_config');
    }
}
