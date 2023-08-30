<?php

namespace Laravel\Pulse\Recorders;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use Laravel\Pulse\Entries\Entry;
use Laravel\Pulse\Pulse;
use Throwable;

/**
 * @internal
 */
class Exceptions
{
    use Concerns\ConfiguresAfterResolving;

    /**
     * The table to record to.
     */
    public string $table = 'pulse_exceptions';

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected Pulse $pulse,
    ) {
        //
    }

    /**
     * Register the recorder.
     */
    public function register(callable $record, Application $app): void
    {
        $this->afterResolving($app, ExceptionHandler::class, fn (ExceptionHandler $handler) => $handler->reportable(fn (Throwable $e) => $record($e)));
    }

    /**
     * Record the exception.
     */
    public function record(Throwable $e): Entry
    {
        $now = new CarbonImmutable();

        [$class, $location] = $this->getDetails($e);

        return new Entry($this->table, [
            'date' => $now->toDateTimeString(),
            'class' => $class,
            'location' => $location,
            'user_id' => $this->pulse->authenticatedUserIdResolver(),
        ]);
    }

    /**
     * Get the exception details.
     *
     * @return array{0: string, 1: string}
     */
    protected function getDetails(Throwable $e): array
    {
        return match (true) {
            $e instanceof \Illuminate\View\ViewException => [
                get_class($e->getPrevious()),
                $this->getLocationFromViewException($e),
            ],

            $e instanceof \Spatie\LaravelIgnition\Exceptions\ViewException => [
                get_class($e->getPrevious()),
                $this->formatLocation($e->getFile(), $e->getLine()),
            ],

            default => [
                get_class($e),
                $this->getLocation($e),
            ]
        };

    }

    /*
     * Get the location of the original view file instead of the cached version.
     */
    protected function getLocationFromViewException(Throwable $e): string
    {
        // Getting the line number in the view file is a bit tricky.
        preg_match('/\(View: (?P<path>.*?)\)/', $e->getMessage(), $matches);

        return $this->formatLocation($matches['path'], null);
    }

    /**
     * Get the location for the given exception.
     */
    protected function getLocation(Throwable $e): string
    {
        $firstNonVendorFrame = collect($e->getTrace())
            ->firstWhere(fn (array $frame) => isset($frame['file']) && $this->isNonVendorFile($frame['file']));

        if ($this->isNonVendorFile($e->getFile()) || $firstNonVendorFrame === null) {
            return $this->formatLocation($e->getFile(), $e->getLine());
        }

        return $this->formatLocation($firstNonVendorFrame['file'], $firstNonVendorFrame['line']);
    }

    /**
     * Determine whether a file is in the vendor directory.
     */
    protected function isNonVendorFile(string $file): bool
    {
        return ! Str::startsWith($file, base_path('vendor'));
    }

    /**
     * Format a file and line number and strip the base path.
     */
    protected function formatLocation(string $file, ?int $line): string
    {
        return Str::replaceFirst(base_path('/'), '', $file).(is_int($line) ? (':'.$line) : '');
    }
}