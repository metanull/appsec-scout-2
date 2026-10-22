<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\ManagesIntegrationCredentials;
use App\Mcp\Support\McpClientConfig;
use App\Models\User;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Phiki\Grammar\Grammar;
use RuntimeException;

class ProfileIntegrationsPage extends Page implements HasForms
{
    use ManagesIntegrationCredentials;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?string $slug = 'profile/integrations';

    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        $this->mountManagesIntegrationCredentials();
    }

    public static function canAccess(): bool
    {
        return Auth::check();
    }

    public function heading(): string
    {
        return 'Profile integrations';
    }

    public function subheading(): string
    {
        return 'Manage the personal credentials used for tracker and source actions you run from the UI.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            $this->mcpClientSection(),
        ]);
    }

    /**
     * The ready-to-paste configuration that lets Claude Desktop or Claude Code start the MCP
     * server as this user (see docs/mcp.md).
     */
    private function mcpClientSection(): Section
    {
        $section = Section::make('Claude (MCP server)')
            ->description('Lets Claude Desktop or Claude Code triage findings as you, with your role\'s permissions. The server starts through Docker, so it works only on a machine where you can run docker commands against this stack.');

        /** @var User $user */
        $user = Auth::user();
        $config = app(McpClientConfig::class);

        try {
            $desktopConfig = $config->desktopConfig($user);
            $claudeCodeCommand = $config->claudeCodeCommand($user);
            $printCommand = $config->printCommand($user);
        } catch (RuntimeException $e) {
            return $section->schema([
                TextEntry::make('mcp_client_unavailable')
                    ->label('Configuration unavailable')
                    ->state($e->getMessage())
                    ->color('danger')
                    ->columnSpanFull(),
            ]);
        }

        return $section->schema([
            CodeEntry::make('mcp_client_desktop')
                ->label('Claude Desktop: add to claude_desktop_config.json, then restart Claude Desktop')
                ->state($desktopConfig)
                ->grammar(Grammar::Json)
                ->jsonFlags(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                ->copyable()
                ->columnSpanFull(),
            TextEntry::make('mcp_client_claude_code')
                ->label('Claude Code: run once in a terminal')
                ->state($claudeCodeCommand)
                ->fontFamily('mono')
                ->copyable()
                ->columnSpanFull(),
            TextEntry::make('mcp_client_print')
                ->label('Print the JSON from a terminal (add --format=claude-code for the command)')
                ->state($printCommand)
                ->fontFamily('mono')
                ->copyable()
                ->columnSpanFull(),
        ]);
    }

    protected function credentialOwnerId(): ?int
    {
        $userId = Auth::id();

        if (is_int($userId)) {
            return $userId;
        }

        if (is_string($userId) && $userId !== '' && ctype_digit($userId)) {
            return (int) $userId;
        }

        return null;
    }
}
