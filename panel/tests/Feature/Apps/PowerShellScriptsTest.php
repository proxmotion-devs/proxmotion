<?php

namespace Tests\Feature\Apps;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The bundled PowerShell files (panel/recipes/_lib and the Windows recipes,
 * docs/adr/0029) parse, and recipe scripts stop on errors. Parsing needs
 * PowerShell (pwsh on Linux runners, Windows PowerShell locally); without it
 * that part is skipped.
 */
class PowerShellScriptsTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function scripts(): array
    {
        $files = [];

        foreach ((new Finder)->files()->in(base_path('recipes'))->name('*.ps1') as $file) {
            $files[] = $file->getRealPath();
        }

        sort($files);

        return $files;
    }

    public function test_recipe_scripts_stop_on_errors_and_use_the_helpers()
    {
        foreach ($this->scripts() as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen(base_path('recipes')) + 1));

            if (str_starts_with($relative, '_lib/') || str_ends_with($relative, '/lib.ps1')) {
                continue;
            }

            $content = (string) file_get_contents($file);
            $this->assertStringContainsString("\$ErrorActionPreference = 'Stop'", $content, $relative);
            $this->assertStringContainsString('. "$env:PROXMOTION_APP_DIR\proxmotion.ps1"', $content, $relative);
        }
    }

    public function test_every_powershell_file_parses()
    {
        $shell = (new ExecutableFinder)->find('pwsh') ?? (PHP_OS_FAMILY === 'Windows' ? (new ExecutableFinder)->find('powershell') : null);

        if ($shell === null) {
            $this->markTestSkipped('PowerShell is not installed.');
        }

        $check = <<<'PS1'
            $failed = 0
            foreach ($path in $input) {
                $tokens = $null; $errors = $null
                [void][System.Management.Automation.Language.Parser]::ParseFile($path, [ref] $tokens, [ref] $errors)
                foreach ($e in $errors) { $failed++; [Console]::Out.WriteLine("${path}:$($e.Extent.StartLineNumber): $($e.Message)") }
            }
            exit $failed
            PS1;

        $process = new Process([$shell, '-NoProfile', '-NonInteractive', '-Command', $check]);
        $process->setInput(implode("\n", $this->scripts()));
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }
}
