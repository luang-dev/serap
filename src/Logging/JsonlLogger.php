<?php

namespace Zzzul\Gol\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

class JsonlLogger extends AbstractLogger implements LoggerInterface
{
    protected string $logPath;

    public function __construct(string $logPath)
    {
        $this->logPath = $logPath;
    }

    public function log($level, $message, array $context = []): void
    {
        $record = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'time' => now()->toISOString(),
        ];

        info('JsonlLogger called');

        file_put_contents(
            $this->logPath,
            json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
