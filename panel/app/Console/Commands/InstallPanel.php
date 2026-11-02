<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateAdministrator;
use App\Console\Commands\Concerns\PreparesPanel;
use App\Domain\Installation\Requirements;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;

#[Signature('proxmotion:install
    {--admin-name= : Name of the first administrator}
    {--admin-email= : Email address of the first administrator}')]
#[Description('Install the panel: check requirements, generate the application key, migrate, create the first administrator (docs/adr/0052)')]
class InstallPanel extends Command
{
    use PreparesPanel;

    private const array ADMIN_OPTIONS = ['admin-name', 'admin-email'];

    public function handle(Requirements $requirements, CreateAdministrator $creator): int
    {
        $arguments = [];

        foreach (self::ADMIN_OPTIONS as $option) {
            if (is_string($value = $this->option($option))) {
                $arguments[] = "--{$option}={$value}";
            }
        }

        return $this->prepare($arguments, fn () => $this->install($requirements, $creator));
    }

    private function install(Requirements $requirements, CreateAdministrator $creator): int
    {
        if (blank(config('app.key')) && $this->environmentFileHasEmptyKey()) {
            $this->call('key:generate', ['--force' => true]);
        }

        if (! $this->checkRequirements($requirements) || ! $this->migrate()) {
            return self::FAILURE;
        }

        if (User::query()->exists()) {
            $this->components->info('Administrators exist already: none was created.');
        } elseif (! $this->createAdministrator($creator)) {
            return self::FAILURE;
        }

        if (! $this->attempt(fn () => $this->cacheForProduction())) {
            return self::FAILURE;
        }

        $this->components->info('Proxmotion is installed. Sign in at '.route('login').' and set up two-factor authentication.');

        return self::SUCCESS;
    }

    /**
     * Only an empty `APP_KEY=` line is filled in. A key in the file with an
     * empty APP_KEY in the environment (which wins) would otherwise get the
     * new key put in front of it; containers without a .env file have to be
     * given the key (the requirements say how).
     */
    private function environmentFileHasEmptyKey(): bool
    {
        $file = $this->laravel->environmentFilePath();

        return is_file($file) && preg_match('/^APP_KEY=[ \t]*$/m', (string) file_get_contents($file)) === 1;
    }

    private function createAdministrator(CreateAdministrator $creator): bool
    {
        $interactive = $this->input->isInteractive();
        $name = $this->option('admin-name') ?? ($interactive ? $this->ask('Name of the first administrator') : null);
        $email = $this->option('admin-email') ?? ($interactive ? $this->ask('Email address of the first administrator') : null);

        if (blank($name) || blank($email)) {
            $this->components->error($interactive
                ? 'The first administrator needs a name and an email address.'
                : 'Give --admin-name and --admin-email to create the first administrator without questions.');

            return false;
        }

        $password = $interactive ? (string) $this->secret('Password (leave empty to generate one)') : '';
        $generated = $password === '';

        if ($generated) {
            $password = Str::password(24);
        }

        $validator = Validator::make(compact('name', 'email', 'password'), CreateAdministrator::rules(checkPassword: ! $generated));

        if ($validator->fails()) {
            $this->components->error('The administrator was not created.');
            $this->components->bulletList($validator->errors()->all());

            return false;
        }

        /** @var array{name: string, email: string, password: string} $validated */
        $validated = $validator->validated();
        $user = $creator->create($validated, null, properties: ['source' => 'install']);

        $this->components->info("Administrator {$user->email} created.");

        if ($generated) {
            $this->components->warn('Password of '.$user->email.': '.OutputFormatter::escape($password));
            $this->components->warn('It is shown only this once: store it, or change it after signing in.');
        }

        return true;
    }
}
