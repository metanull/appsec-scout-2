<?php

use App\SourceControl\Collection\PackageFeedCredentialWriter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

const FEED_TEST_ORG_FEED = 'https://pkgs.dev.azure.com/TestOrg/_packaging/private-feed/nuget/v3/index.json';

function feedTestRoot(): string
{
    $root = storage_path('framework/testing/feed-credentials-' . Str::uuid());
    File::ensureDirectoryExists($root . '/work');
    File::ensureDirectoryExists($root . '/home');

    return $root;
}

/** @param array<string, string> $sources key => url */
function feedTestNugetConfig(array $sources): string
{
    $adds = '';

    foreach ($sources as $key => $url) {
        $adds .= '<add key="' . htmlspecialchars($key, ENT_XML1 | ENT_QUOTES) . '" value="' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES) . '" />';
    }

    return '<?xml version="1.0" encoding="utf-8"?><configuration><packageSources><clear />' . $adds . '</packageSources></configuration>';
}

/** @return array<string, array{username: string, password: string}> source key (element name) => credentials */
function feedTestReadNugetCredentials(string $home): array
{
    $document = new DOMDocument;
    $document->loadXML(File::get($home . '/.nuget/NuGet/NuGet.Config'));
    $credentials = [];

    foreach ((new DOMXPath($document))->query('/configuration/packageSourceCredentials/*') as $source) {
        $settings = [];

        foreach ($source->childNodes as $add) {
            if ($add instanceof DOMElement) {
                $settings[$add->getAttribute('key')] = $add->getAttribute('value');
            }
        }

        $credentials[$source->nodeName] = ['username' => $settings['Username'] ?? '', 'password' => $settings['ClearTextPassword'] ?? ''];
    }

    return $credentials;
}

beforeEach(function () {
    $this->root = feedTestRoot();
    $this->work = $this->root . '/work';
    $this->home = $this->root . '/home';
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('writes NuGet credentials for a source on the organization feed', function () {
    File::put($this->work . '/nuget.config', feedTestNugetConfig([
        'nuget' => 'https://api.nuget.org/v3/index.json',
        'private-feed' => FEED_TEST_ORG_FEED,
    ]));

    $result = (new PackageFeedCredentialWriter)->write($this->work, $this->home, 'testorg', 'secret-pat');

    expect($result->nugetSources)->toBe(['private-feed'])
        ->and($result->mavenRepositories)->toBe([])
        ->and($result->mavenSettingsPath)->toBeNull()
        ->and(feedTestReadNugetCredentials($this->home))->toBe([
            'private-feed' => ['username' => 'azdo', 'password' => 'secret-pat'],
        ])
        ->and(fileperms($this->home . '/.nuget/NuGet/NuGet.Config') & 0777)->toBe(0600)
        ->and(File::exists($this->home . '/.m2/settings.xml'))->toBeFalse();
});

it('finds nuget.config files in subdirectories, whatever their case', function () {
    File::ensureDirectoryExists($this->work . '/src/Api');
    File::put($this->work . '/src/Api/NuGet.Config', feedTestNugetConfig(['api-feed' => FEED_TEST_ORG_FEED]));
    File::put($this->work . '/NUGET.CONFIG', feedTestNugetConfig(['root-feed' => FEED_TEST_ORG_FEED]));

    $result = (new PackageFeedCredentialWriter)->write($this->work, $this->home, 'TestOrg', 'secret-pat');

    expect($result->nugetSources)->toBe(['api-feed', 'root-feed']);
});

it('writes nothing when no source is on the organization feed', function () {
    File::put($this->work . '/nuget.config', feedTestNugetConfig([
        'nuget' => 'https://api.nuget.org/v3/index.json',
        'other-org' => 'https://pkgs.dev.azure.com/OtherOrg/_packaging/feed/nuget/v3/index.json',
        'lookalike' => 'https://pkgs.dev.azure.com.attacker.example/TestOrg/_packaging/feed/nuget/v3/index.json',
        'plain-http' => 'http://pkgs.dev.azure.com/TestOrg/_packaging/feed/nuget/v3/index.json',
    ]));

    $result = (new PackageFeedCredentialWriter)->write($this->work, $this->home, 'testorg', 'secret-pat');

    expect($result->isEmpty())->toBeTrue()
        ->and(File::exists($this->home . '/.nuget/NuGet/NuGet.Config'))->toBeFalse();
});

it('leaves out a source key that another file points at a different host', function () {
    File::put($this->work . '/nuget.config', feedTestNugetConfig(['shared' => FEED_TEST_ORG_FEED, 'private-feed' => FEED_TEST_ORG_FEED]));
    File::ensureDirectoryExists($this->work . '/tools');
    File::put($this->work . '/tools/nuget.config', feedTestNugetConfig(['shared' => 'https://feed.example.com/v3/index.json']));

    $result = (new PackageFeedCredentialWriter)->write($this->work, $this->home, 'testorg', 'secret-pat');

    expect($result->nugetSources)->toBe(['private-feed'])
        ->and(array_keys(feedTestReadNugetCredentials($this->home)))->toBe(['private-feed']);
});

it('keeps a PAT with XML special characters intact', function () {
    File::put($this->work . '/nuget.config', feedTestNugetConfig(['private-feed' => FEED_TEST_ORG_FEED]));
    File::put($this->work . '/pom.xml', '<project><repositories><repository><id>java-feed</id><url>https://pkgs.dev.azure.com/TestOrg/_packaging/java/maven/v1</url></repository></repositories></project>');
    $pat = 'a"b<c>&d\'e';

    $result = (new PackageFeedCredentialWriter)->write($this->work, $this->home, 'testorg', $pat);

    $settings = new DOMDocument;
    $settings->loadXML(File::get($result->mavenSettingsPath));

    expect(feedTestReadNugetCredentials($this->home)['private-feed']['password'])->toBe($pat)
        ->and($settings->getElementsByTagName('password')->item(0)->textContent)->toBe($pat);
});

it('encodes a source key that is not a valid XML name the way NuGet does', function (string $key, string $element) {
    expect(PackageFeedCredentialWriter::encodeXmlName($key))->toBe($element);
})->with([
    'plain' => ['nuget-eesc-cor', 'nuget-eesc-cor'],
    'space' => ['My Feed', 'My_x0020_Feed'],
    'leading digit' => ['1feed', '_x0031_feed'],
    'leading dash' => ['-feed', '_x002D_feed'],
    'escape sequence' => ['a_xb', 'a_x005F_xb'],
    'at sign' => ['java@Local', 'java_x0040_Local'],
]);

it('stores a NuGet source with an encoded key under its encoded element name', function () {
    File::put($this->work . '/nuget.config', feedTestNugetConfig(['Private Feed' => FEED_TEST_ORG_FEED]));

    $result = (new PackageFeedCredentialWriter)->write($this->work, $this->home, 'testorg', 'secret-pat');

    expect($result->nugetSources)->toBe(['Private Feed'])
        ->and(array_keys(feedTestReadNugetCredentials($this->home)))->toBe(['Private_x0020_Feed']);
});

it('recognises only the organization feed hosts', function (string $url, bool $expected) {
    expect(PackageFeedCredentialWriter::isOrganizationFeed($url, 'TestOrg'))->toBe($expected);
})->with([
    'organization feed' => [FEED_TEST_ORG_FEED, true],
    'project-scoped feed' => ['https://pkgs.dev.azure.com/testorg/SomeProject/_packaging/feed/nuget/v3/index.json', true],
    'legacy host' => ['https://testorg.pkgs.visualstudio.com/_packaging/feed/nuget/v3/index.json', true],
    'encoded view' => ['https://pkgs.dev.azure.com/TestOrg/_packaging/java-releases%40Local/maven/v1', true],
    'other organization' => ['https://pkgs.dev.azure.com/OtherOrg/_packaging/feed/nuget/v3/index.json', false],
    'organization as a prefix' => ['https://pkgs.dev.azure.com/TestOrgX/_packaging/feed/nuget/v3/index.json', false],
    'nuget.org' => ['https://api.nuget.org/v3/index.json', false],
    'lookalike host' => ['https://testorg.pkgs.visualstudio.com.attacker.example/_packaging/feed', false],
    'plain http' => ['http://pkgs.dev.azure.com/TestOrg/_packaging/feed/nuget/v3/index.json', false],
    'not a url' => ['%PRIVATE_FEED%', false],
]);

it('writes Maven server credentials for repositories and plugin repositories on the organization feed', function () {
    File::put($this->work . '/pom.xml', <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <project xmlns="http://maven.apache.org/POM/4.0.0">
          <repositories>
            <repository><id>java-feed</id><url>https://pkgs.dev.azure.com/TestOrg/_packaging/java-feed/maven/v1</url></repository>
            <repository><id>central</id><url>https://repo.maven.apache.org/maven2</url></repository>
          </repositories>
          <pluginRepositories>
            <pluginRepository><id>java-plugins</id><url>https://pkgs.dev.azure.com/TestOrg/_packaging/java-feed/maven/v1</url></pluginRepository>
          </pluginRepositories>
        </project>
        XML);

    $result = (new PackageFeedCredentialWriter)->write($this->work, $this->home, 'testorg', 'secret-pat');

    expect($result->mavenRepositories)->toBe(['java-feed', 'java-plugins'])
        ->and($result->mavenSettingsPath)->toBe($this->home . '/.m2/settings.xml')
        ->and(fileperms($result->mavenSettingsPath) & 0777)->toBe(0600);

    $settings = new DOMDocument;
    $settings->loadXML(File::get($result->mavenSettingsPath));
    $servers = [];

    foreach ($settings->getElementsByTagName('server') as $server) {
        $servers[$server->getElementsByTagName('id')->item(0)->textContent] = [
            $server->getElementsByTagName('username')->item(0)->textContent,
            $server->getElementsByTagName('password')->item(0)->textContent,
        ];
    }

    expect($servers)->toBe([
        'java-feed' => ['azdo', 'secret-pat'],
        'java-plugins' => ['azdo', 'secret-pat'],
    ]);
});

it('ignores empty and malformed files', function () {
    File::put($this->work . '/nuget.config', '');
    File::put($this->work . '/pom.xml', '<project><repositories>');

    $result = (new PackageFeedCredentialWriter)->write($this->work, $this->home, 'testorg', 'secret-pat');

    expect($result->isEmpty())->toBeTrue()
        ->and(File::exists($this->home . '/.nuget'))->toBeFalse()
        ->and(File::exists($this->home . '/.m2'))->toBeFalse();
});
