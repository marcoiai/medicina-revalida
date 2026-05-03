<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class QuestionsSyncStudyGeneralWebCommand extends Command
{
    protected $signature = 'questions:sync-study-general-web
        {--sources-file= : Arquivo JSON alternativo com fontes oficiais a serem rastreadas}
        {--discover-out=storage/imports/study_general_sources.discovered.json : Saída temporária da descoberta}
        {--merged-sources=storage/imports/study_general_sources.merged.json : Arquivo temporário com fontes mescladas para estudo geral}
        {--discover-max-depth=2 : Profundidade máxima da descoberta de fontes}
        {--discover-max-pages=80 : Máximo de páginas por seed na descoberta}
        {--search-results=10 : Máximo de resultados por consulta de busca}
        {--search-timeout=12 : Timeout em segundos para cada consulta pública}
        {--quiet-discovery : Reduz a verbosidade da descoberta de fontes}
        {--skip-discovery : Pula a descoberta de novas fontes e usa só as seeds já conhecidas}
        {--ai-provider= : Provedor de IA para fallback e revisão (openai ou gemini)}
        {--limit-sources=0 : Limita a quantidade de fontes no crawl}
        {--generate-limit=0 : Limita a quantidade de prompts processados na IA}
        {--skip-ai : Pula apenas o fallback via IA}';

    protected $description = 'Executa apenas o crawl web e a importação como estudo geral';

    public function handle(): int
    {
        $sourcesFile = $this->prepareStudyGeneralSourcesFile(
            trim((string) $this->option('sources-file')),
            base_path((string) $this->option('discover-out')),
            base_path((string) $this->option('merged-sources')),
            (bool) $this->option('skip-discovery')
        );
        $syncOptions = [
            '--category' => 'estudo_geral',
            '--exam' => 'all',
            '--phase' => 'all',
            '--sources-file' => $sourcesFile,
        ];

        $limitSources = (int) $this->option('limit-sources');
        if ($limitSources > 0) {
            $syncOptions['--limit-sources'] = $limitSources;
        }

        $generateLimit = (int) $this->option('generate-limit');
        if ($generateLimit > 0) {
            $syncOptions['--generate-limit'] = $generateLimit;
        }

        if ((bool) $this->option('skip-ai')) {
            $syncOptions['--skip-ai'] = true;
        }

        $aiProvider = strtolower(trim((string) $this->option('ai-provider')));
        if ($aiProvider !== '') {
            $syncOptions['--ai-provider'] = $aiProvider;
        }

        $this->components->info('Executando crawl web apenas para estudo geral...');

        $exitCode = $this->call('questions:sync-web', $syncOptions);
        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        $this->info('Crawl web de estudo geral finalizado com sucesso.');
        return self::SUCCESS;
    }

    private function prepareStudyGeneralSourcesFile(string $customSourcesFile, string $discoverOut, string $mergedOut, bool $skipDiscovery): string
    {
        $inputPath = $customSourcesFile !== ''
            ? $this->makeAbsolutePath($customSourcesFile)
            : base_path('tools/sources.json');

        if (!is_file($inputPath)) {
            return $inputPath;
        }

        $discovered = $skipDiscovery ? $inputPath : $this->runDiscovery($inputPath, $discoverOut);
        $merged = $this->mergeStudyGeneralSources($inputPath, $discovered, $mergedOut);

        return $merged;
    }

    private function makeAbsolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }

    private function runDiscovery(string $sourcesPath, string $discoverOut): string
    {
        File::ensureDirectoryExists(dirname($discoverOut));

        $python = is_file(base_path('.venv/bin/python3'))
            ? base_path('.venv/bin/python3')
            : 'python3';

        $command = [
            $python,
            base_path('tools/discover_official_sources.py'),
            '--sources',
            $sourcesPath,
            '--out',
            $discoverOut,
            '--max-depth',
            (string) max(0, (int) $this->option('discover-max-depth')),
            '--max-pages',
            (string) max(1, (int) $this->option('discover-max-pages')),
            '--search-results',
            (string) max(1, (int) $this->option('search-results')),
            '--search-timeout',
            (string) max(1, (int) $this->option('search-timeout')),
        ];

        if ((bool) $this->option('quiet-discovery')) {
            $command[] = '--quiet';
        }

        $process = new Process($command, base_path(), $_ENV);

        $process->setTimeout(null);
        $lastHeartbeat = microtime(true);
        $process->start(function (string $type, string $buffer) use (&$lastHeartbeat): void {
            $this->output->write($buffer);
            $lastHeartbeat = microtime(true);
        });

        $heartbeat = 0;
        while ($process->isRunning()) {
            usleep(500000);
            $heartbeat++;
            if ($heartbeat % 10 === 0) {
                $this->line('Descoberta de fontes em andamento...');
            }
            if (($heartbeat % 2) === 0 && (microtime(true) - $lastHeartbeat) > 8) {
                $this->line('Descoberta de fontes ainda processando...');
                $lastHeartbeat = microtime(true);
            }
        }

        if (!$process->isSuccessful()) {
            $this->warn('Descoberta falhou; seguindo com as seeds locais.');
        }

        return is_file($discoverOut) ? $discoverOut : $sourcesPath;
    }

    private function mergeStudyGeneralSources(string $baseSourcesPath, string $discoveredSourcesPath, string $mergedOut): string
    {
        $baseSources = $this->loadJsonList($baseSourcesPath);
        $discoveredSources = $this->loadJsonList($discoveredSourcesPath);

        $ordered = [];
        $seen = [];
        $priorityBuckets = [
            'preferred' => [],
            'neutral' => [],
            'last' => [],
        ];

        foreach (array_merge($discoveredSources, $baseSources) as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ($this->isRevalidaSource($item)) {
                continue;
            }

            $url = strtolower(trim((string) ($item['url'] ?? '')));
            if ($url === '' || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $bucket = $this->studyGeneralPriorityBucket($item);
            $priorityBuckets[$bucket][] = $item;
        }

        $ordered = array_merge(
            $priorityBuckets['preferred'],
            $priorityBuckets['neutral'],
            $priorityBuckets['last']
        );

        if ($ordered === []) {
            $ordered = array_values(array_filter($baseSources, fn ($item) => is_array($item)));
        }

        File::ensureDirectoryExists(dirname($mergedOut));
        file_put_contents(
            $mergedOut,
            json_encode($ordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $mergedOut;
    }

    private function studyGeneralPriorityBucket(array $item): string
    {
        $name = strtolower(trim((string) ($item['name'] ?? '')));
        $exam = strtolower(trim((string) ($item['exam'] ?? '')));
        $institution = strtolower(trim((string) ($item['institution'] ?? '')));

        if (str_contains($name, 'enare') || str_contains($exam, 'enare') || str_contains($institution, 'enare')) {
            return 'last';
        }

        if (
            str_contains($name, 'residência') ||
            str_contains($name, 'residencia') ||
            str_contains($exam, 'residência') ||
            str_contains($exam, 'residencia')
        ) {
            return 'preferred';
        }

        return 'neutral';
    }

    private function loadJsonList(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = json_decode((string) file_get_contents($path), true);
        return is_array($raw) ? $raw : [];
    }

    private function isRevalidaSource(array $item): bool
    {
        $name = strtolower(trim((string) ($item['name'] ?? '')));
        $exam = strtolower(trim((string) ($item['exam'] ?? '')));
        $url = strtolower(trim((string) ($item['url'] ?? '')));

        return str_contains($name, 'revalida')
            || str_contains($exam, 'revalida')
            || str_contains($url, 'revalida');
    }
}
