<?php

declare(strict_types=1);

/*
 * The parts of WISECP the module uses, written for the tests (WISECP itself
 * is not open source). Only the behaviour the module relies on is imitated;
 * PHPStan reads the same declarations (phpstan.neon).
 */

namespace {
    if (! defined('CORE_FOLDER')) {
        define('CORE_FOLDER', 'coremio');
    }

    /**
     * WISECP's base class of server modules.
     */
    class ServerModule
    {
        protected bool $dashboard_cache = true;

        /** @var array<string, mixed> */
        public mixed $server = [];

        /** @var array<string, mixed> */
        public array $service = [];

        /** @var array<string, mixed> */
        public array $options = [];

        /** @var array<string, mixed> */
        public array $lang = [];

        /** @var array<string, mixed> */
        public array $requirement_params = [];

        /** @var array<string, mixed> */
        public array $addon_params = [];

        public string $error = '';

        /** @var list<string> tools removed by the module (tests) */
        public array $removedTools = [];

        /** @var list<array{action: string, request: string, response: string}> module log entries (tests) */
        public array $logs = [];

        /**
         * @param array<string, mixed>|int $server
         * @param array<string, mixed> $options
         */
        public function __construct($server = [], $options = [])
        {
            $this->options = $options ?: [];
            $this->lang = include PROXMOTION_WISECP_MODULE . '/lang/en.php';

            if ($server) {
                $this->set_server($server);
            }
        }

        /**
         * @param array<string, mixed>|int $server
         */
        public function set_server(array|int $server = []): void
        {
            $this->server = is_array($server) ? $server : ['id' => $server];

            if (method_exists($this, 'define_server_info')) {
                $this->define_server_info($this->server);
            }
        }

        /**
         * @param array<string, mixed> $fields
         * @param array<string, mixed> $field_defs
         * @return array<string, mixed>
         */
        public static function crypt_decode_fields(array $fields, array $field_defs): array
        {
            return $fields;
        }

        public function get_login_password(): string
        {
            return $this->decode_str((string) ($this->options['login']['password'] ?? ''));
        }

        public function save_options(): bool
        {
            return true;
        }

        public function remove_tool(string $key): static
        {
            $this->removedTools[] = $key;

            return $this;
        }

        protected function encode_str(string $str = '', string $key = ''): string
        {
            return $str === '' ? '' : 'encrypted:' . $str;
        }

        protected function decode_str(string $str = '', string $key = ''): string
        {
            return str_starts_with($str, 'encrypted:') ? substr($str, 10) : '';
        }

        protected function save_log(string $action = '', string $request = '', string $response = '', string $processed = ''): bool|int
        {
            $this->logs[] = ['action' => $action, 'request' => $request, 'response' => $response];

            return true;
        }

        protected function save_login_password(string $plain): bool
        {
            $this->options['login'] = ['username' => 'root', 'password' => $this->encode_str($plain)];

            return true;
        }

        /**
         * @return array<string, mixed>
         */
        protected function os_info_item(string $osName): array
        {
            return ['value' => $osName];
        }

        /**
         * @return array<string, mixed>
         */
        protected function location_info_item(string $location, string $countryCode = ''): array
        {
            return ['value' => $location];
        }

        /**
         * @param array<string, mixed> $extra
         * @return array<string, mixed>
         */
        protected function vm_status_item(string $status, array $extra = []): array
        {
            return array_merge(['value' => ucfirst($status), 'raw' => strtolower($status)], $extra);
        }

        /**
         * @param array<string, mixed> $data
         */
        protected function vm_status_of(array $data): string
        {
            return strtolower((string) ($data['server_info']['status']['raw'] ?? 'unknown'));
        }
    }

    class Language
    {
        public static function gc(string $key): string
        {
            return $key;
        }
    }

    /*
     * Used by WisecpWebhookStore only, which the tests replace with
     * FakeWebhookStore: declared for PHPStan, never called.
     */
    class WdbQuery
    {
        public function from(string $table = ''): self
        {
            return $this;
        }

        public function where(string $column = '', string $mark = '', mixed $value = '', string $logical = ''): self
        {
            return $this;
        }

        public function build(bool $isthis = false): bool|self
        {
            return true;
        }

        public function run(bool $isthis = false): bool|self
        {
            return true;
        }
    }

    class WDB
    {
        public static function select(string $arg = '*'): WdbQuery
        {
            return new WdbQuery;
        }

        public static function delete(string $arg = '', string $arg2 = ''): WdbQuery
        {
            return new WdbQuery;
        }

        /**
         * @param array<string, mixed> $data
         * @phpstan-impure
         */
        public static function insert(string $table, array $data): int
        {
            return 1;
        }

        /**
         * @return list<array<string, mixed>>|false
         */
        public static function fetch_assoc(): array|false
        {
            return [];
        }

        public static function hasTable(string $table = ''): bool
        {
            return true;
        }

        /** @phpstan-impure */
        public static function exec(string $query = ''): int
        {
            return 0;
        }
    }

    class Services
    {
        /**
         * @return array<string, mixed>
         */
        public static function get(int $id = 0, string $select = '', bool $noCache = false): array
        {
            return [];
        }

        /**
         * @param array<string, mixed> $data
         * @phpstan-impure
         */
        public static function set(int $id = 0, array $data = []): bool
        {
            return true;
        }

        /**
         * @param array<string, mixed> $options
         * @phpstan-impure
         */
        public static function change_status(int $serviceId, string $status, array $options = []): bool
        {
            return true;
        }

        /**
         * @param array<string, mixed> $data
         * @phpstan-impure
         */
        public static function add_history(int $user_id = 0, int $service_id = 0, string $name = '', array $data = []): int
        {
            return 1;
        }
    }

    class Notification
    {
        /**
         * @param array<string, mixed> $data
         * @phpstan-impure sends e-mails
         */
        public static function dispatch(string $group, string $template, array $data = []): mixed
        {
            return true;
        }
    }

    class Modules
    {
        /** @phpstan-impure writes the module log */
        public static function save_log(string $type = '', string $module = '', string $action = '', string $request = '', string $response = '', string $processed = ''): bool|int
        {
            return true;
        }
    }

    class Hook
    {
        /** @var list<callable> listeners of filter:api.routes (tests) */
        public static array $listeners = [];

        public static function add(string $name, int $priority, callable $listener): void
        {
            self::$listeners[] = $listener;
        }

        public static function reset(): void
        {
            self::$listeners = [];
        }
    }
}

namespace WISECP\Api\Core {
    class Request
    {
        public string $method = 'GET';

        /** @var array<string, string> header names in lower case */
        public array $headers = [];

        public string $rawBody = '';
    }

    class Response
    {
        /**
         * @param array<string, mixed> $payload
         */
        public function __construct(public int $status = 200, public array $payload = []) {}
    }
}
