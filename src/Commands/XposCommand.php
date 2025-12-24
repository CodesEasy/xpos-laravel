<?php

namespace CodesEasy\Xpos\Commands;

use CodesEasy\Xpos\Support\ServeManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'xpos')]
class XposCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'xpos
        {--port= : The port to use for the development server}
        {--host=127.0.0.1 : The host to bind the development server to}
        {--no-serve : Skip starting artisan serve (use existing server)}';

    /**
     * The console command description.
     */
    protected $description = 'Create an XPOS tunnel to expose your Laravel app publicly';

    /**
     * The SSH tunnel process.
     */
    protected ?Process $tunnelProcess = null;

    /**
     * The serve process (if we started it).
     */
    protected ?Process $serveProcess = null;

    /**
     * Whether we started the serve process ourselves.
     */
    protected bool $weStartedServe = false;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->displayBanner();

        // Check if SSH is available
        if (!$this->sshExists()) {
            $this->error('  SSH client not found');
            $this->line('  <fg=gray>Please install OpenSSH client to use XPOS</>');
            return self::FAILURE;
        }

        $serveManager = new ServeManager();
        $port = $this->determinePort($serveManager);

        if ($port === null) {
            return self::FAILURE;
        }

        // Create the tunnel
        $this->newLine();
        $this->line('  <fg=gray>Creating tunnel to XPOS...</>');

        $tunnelResult = $this->createTunnel($port);

        if ($tunnelResult !== self::SUCCESS) {
            $this->cleanup($serveManager);
            return $tunnelResult;
        }

        // Keep running until user presses Ctrl+C (Unix) or process ends (Windows)
        $this->registerShutdownHandler($serveManager);

        // Wait for tunnel process
        while ($this->tunnelProcess && $this->tunnelProcess->isRunning()) {
            usleep(100000); // 100ms
        }

        $this->cleanup($serveManager);

        return self::SUCCESS;
    }

    /**
     * Register shutdown handler for graceful cleanup.
     */
    protected function registerShutdownHandler(ServeManager $serveManager): void
    {
        // Unix signals (Linux/macOS)
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_signal')) {
            if (defined('SIGINT')) {
                $this->trap([SIGINT, SIGTERM], function () use ($serveManager) {
                    $this->newLine();
                    $this->line('  <fg=yellow>Shutting down...</>');
                    $this->cleanup($serveManager);
                    exit(0);
                });
            }
        }

        // Fallback: register shutdown function for all platforms
        register_shutdown_function(function () use ($serveManager) {
            $this->cleanup($serveManager);
        });
    }

    /**
     * Check if SSH client is available.
     */
    protected function sshExists(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $process = new Process(['where', 'ssh']);
        } else {
            $process = new Process(['which', 'ssh']);
        }

        $process->run();

        return $process->isSuccessful() && !empty(trim($process->getOutput()));
    }

    /**
     * Display the XPOS banner.
     */
    protected function displayBanner(): void
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>XPOS Tunnel</>');
        $this->line('  <fg=gray>─────────────────────────────────────────</>');
    }

    /**
     * Determine which port to use for the tunnel.
     */
    protected function determinePort(ServeManager $serveManager): ?int
    {
        $host = $this->option('host');

        // If --no-serve, require an already running server
        if ($this->option('no-serve')) {
            $portOption = $this->option('port');
            $port = $portOption !== null ? (int) $portOption : (int) config('xpos.default_port', 8000);

            if (!$serveManager->isPortListening($port, $host)) {
                $this->error("  No server running on {$host}:{$port}");
                $this->line('  <fg=gray>Start your server first or remove --no-serve flag</>');
                return null;
            }

            $this->line("  <fg=green>✓</> Using existing server on <fg=white>http://{$host}:{$port}</>");
            return $port;
        }

        // Check if serve is already running for this project
        $existingPort = $serveManager->getRunningPort();
        if ($existingPort !== null) {
            $this->line("  <fg=green>✓</> Found running server on <fg=white>http://{$host}:{$existingPort}</>");
            return $existingPort;
        }

        // Start a new serve process
        $portOption = $this->option('port');
        $defaultPort = $portOption !== null ? (int) $portOption : (int) config('xpos.default_port', 8000);

        try {
            $this->line('  <fg=gray>Starting development server...</>');

            $result = $serveManager->startServe($defaultPort, $host);

            $this->serveProcess = $result['process'];
            $this->weStartedServe = true;

            $this->line("  <fg=green>✓</> Server running on <fg=white>http://{$host}:{$result['port']}</>");

            return $result['port'];
        } catch (\RuntimeException $e) {
            $this->error('  ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create the SSH tunnel to XPOS.
     */
    protected function createTunnel(int $port): int
    {
        $server = config('xpos.server', 'go.xpos.dev');
        $sshPort = config('xpos.ssh_port', 443);
        $sshUser = config('xpos.ssh_user', 'x');
        $host = $this->option('host');

        // Build SSH command: ssh -p 443 -R0:127.0.0.1:PORT x@go.xpos.dev
        $command = [
            'ssh',
            '-p', (string) $sshPort,
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'UserKnownHostsFile=/dev/null',
            '-o', 'LogLevel=ERROR',
            '-o', 'BatchMode=yes',           // Prevent interactive prompts
            '-o', 'ConnectTimeout=10',       // Connection timeout
            '-R', "0:{$host}:{$port}",
            "{$sshUser}@{$server}",
        ];

        $this->tunnelProcess = new Process($command);
        $this->tunnelProcess->setTimeout(null);
        $this->tunnelProcess->setTty(Process::isTtySupported());

        $publicUrl = null;
        $urlDisplayed = false;
        $outputBuffer = '';

        $this->tunnelProcess->start(function ($type, $buffer) use (&$publicUrl, &$urlDisplayed, &$outputBuffer) {
            // Accumulate output for better URL detection
            $outputBuffer .= $buffer;

            // Parse output for the public URL (supports alphanumeric and hyphens, case-insensitive)
            if (!$publicUrl && preg_match('/(https:\/\/[a-z0-9-]+\.xpos\.to)/i', $outputBuffer, $matches)) {
                $publicUrl = $matches[1];

                if (!$urlDisplayed) {
                    $urlDisplayed = true;
                    $this->displayTunnelUrl($publicUrl);
                }
            }

            // Show other output (filtered)
            $lines = explode("\n", trim($buffer));
            foreach ($lines as $line) {
                $line = trim($line);

                // Skip empty lines and URL lines (we display URL separately)
                if (empty($line) || str_contains($line, 'xpos.to')) {
                    continue;
                }

                // Skip the "Your public URL" line
                if (str_contains($line, 'public URL')) {
                    continue;
                }

                // Skip "Press Ctrl+C" line (we show our own)
                if (str_contains($line, 'Ctrl+C')) {
                    continue;
                }

                // Show remaining output in gray
                if (!empty($line)) {
                    $this->line("  <fg=gray>{$line}</>");
                }
            }
        });

        // Wait for URL to appear (max 15 seconds)
        $waitedMs = 0;
        $maxWaitMs = 15000; // 15 seconds
        while (!$publicUrl && $waitedMs < $maxWaitMs && $this->tunnelProcess->isRunning()) {
            usleep(100000); // 100ms
            $waitedMs += 100;

            // Show progress dots every 2 seconds
            if ($waitedMs > 0 && $waitedMs % 2000 === 0 && !$urlDisplayed) {
                $this->output->write('.');
            }
        }

        if (!$this->tunnelProcess->isRunning()) {
            $this->newLine();
            $this->error('  Tunnel connection failed');
            $errorOutput = trim($this->tunnelProcess->getErrorOutput());
            if (!empty($errorOutput)) {
                $this->line("  <fg=gray>{$errorOutput}</>");
            }
            return self::FAILURE;
        }

        if (!$publicUrl) {
            $this->newLine();
            $this->warn('  <fg=yellow>Tunnel connected but URL not detected</>');
            $this->line('  <fg=gray>Check the output above for your URL</>');
        }

        return self::SUCCESS;
    }

    /**
     * Display the tunnel URL in a nice box.
     */
    protected function displayTunnelUrl(string $url): void
    {
        $this->newLine();

        $padding = 3;
        $urlLength = strlen($url);
        $boxWidth = $urlLength + ($padding * 2);
        $horizontalLine = str_repeat('─', $boxWidth);
        $emptySpace = str_repeat(' ', $padding);

        $this->line("  <fg=green>┌{$horizontalLine}┐</>");
        $this->line("  <fg=green>│</>{$emptySpace}<fg=white;options=bold>{$url}</>{$emptySpace}<fg=green>│</>");
        $this->line("  <fg=green>└{$horizontalLine}┘</>");

        $this->newLine();
        $this->line('  <fg=gray>Tunnel active. Press</> <fg=yellow>Ctrl+C</> <fg=gray>to stop.</>');
        $this->newLine();
    }

    /**
     * Clean up processes on exit.
     */
    protected function cleanup(ServeManager $serveManager): void
    {
        // Stop tunnel process
        if ($this->tunnelProcess && $this->tunnelProcess->isRunning()) {
            $this->tunnelProcess->stop(3);
        }

        // Stop serve process only if we started it
        if ($this->weStartedServe && $this->serveProcess && $this->serveProcess->isRunning()) {
            $this->serveProcess->stop(3);
            $serveManager->cleanup();
        }
    }
}
