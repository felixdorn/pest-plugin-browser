<?php

declare(strict_types=1);

namespace Pest\Browser;

use Pest\Browser\Contracts\HttpServer;
use Pest\Browser\Contracts\PlaywrightServer;
use Pest\Browser\Drivers\LaravelHttpServer;
use Pest\Browser\Drivers\NullableHttpServer;
use Pest\Browser\Playwright\Servers\AlreadyStartedPlaywrightServer;
use Pest\Browser\Playwright\Servers\PlaywrightNpmServer;
use Pest\Browser\Support\PackageJsonDirectory;
use Pest\Browser\Support\Port;
use Pest\Plugins\Parallel;

/**
 * @internal
 *
 * @codeCoverageIgnore
 */
final class ServerManager
{
    /**
     * The default host for the server.
     */
    public const string DEFAULT_HOST = "127.0.0.1";

    /**
     * The singleton instance of the server manager.
     */
    private static ?ServerManager $instance = null;

    /**
     * The Playwright server process.
     */
    private ?PlaywrightServer $playwright = null;

    /**
     * The HTTP server process.
     */
    private ?HttpServer $http = null;

    /**
     * Gets the singleton instance of the server manager.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Returns the Playwright server process instance.
     */
    public function playwright(): PlaywrightServer
    {
        // If already resolved, return early.
        if ($this->playwright instanceof PlaywrightServer) {
            return $this->playwright;
        }

        // Prefer an externally provided Playwright server via env var.
        $external =
            getenv("PEST_PLAYWRIGHT_SERVER_URL") ?:
            getenv("PLAYWRIGHT_SERVER_URL");
        if (is_string($external) && $external !== "") {
            // Normalize and parse (accepts "127.0.0.1:4555", "ws://127.0.0.1:4555", "http://127.0.0.1:4555")
            if (
                !str_starts_with($external, "ws://") &&
                !str_starts_with($external, "http://")
            ) {
                $external = "ws://" . $external;
            }

            $parts = parse_url($external);
            $host = is_string($parts["host"] ?? null)
                ? $parts["host"]
                : self::DEFAULT_HOST;
            $port = isset($parts["port"]) ? (int) $parts["port"] : 0;

            if ($port <= 0) {
                throw new \InvalidArgumentException(
                    "PLAYWRIGHT_SERVER_URL must include a port, e.g., 127.0.0.1:4555",
                );
            }

            AlreadyStartedPlaywrightServer::persist($host, $port);

            return $this->playwright = new AlreadyStartedPlaywrightServer(
                $host,
                $port,
            );
        }

        // Workers always use the persisted server information.
        if (Parallel::isWorker()) {
            return AlreadyStartedPlaywrightServer::fromPersisted();
        }

        // If a server was persisted (for example, started externally), use it even in non-parallel mode.
        try {
            $persisted = AlreadyStartedPlaywrightServer::fromPersisted();
            return $this->playwright = $persisted;
        } catch (\Throwable $e) {
            // No persisted server available; fall back to starting our own.
        }

        // Start a local Playwright server via npm.
        $port = Port::find();

        $this->playwright = PlaywrightNpmServer::create(
            PackageJsonDirectory::find(),
            "." .
                DIRECTORY_SEPARATOR .
                "node_modules" .
                DIRECTORY_SEPARATOR .
                ".bin" .
                DIRECTORY_SEPARATOR .
                "playwright run-server --host %s --port %d --mode launchServer",
            self::DEFAULT_HOST,
            $port,
            "Listening on",
        );

        AlreadyStartedPlaywrightServer::persist(self::DEFAULT_HOST, $port);

        return $this->playwright;
    }

    /**
     * Returns the HTTP server process instance.
     */
    public function http(): HttpServer
    {
        return $this->http ??= match (function_exists("app_path")) {
            true => new LaravelHttpServer(self::DEFAULT_HOST, Port::find()),
            default => new NullableHttpServer(),
        };
    }
}
