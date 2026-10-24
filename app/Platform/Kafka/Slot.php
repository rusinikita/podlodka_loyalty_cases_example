<?php

declare(strict_types=1);

namespace App\Platform\Kafka;

use RdKafka\Message as KafkaMessage;
use RuntimeException;

/**
 * A handler process. It gets one message at a time on stdin and writes the
 * outcome to file descriptor 3. Logs go to the shared stdout.
 */
final class Slot
{
    /** @var resource */
    private $process;

    /** @var resource */
    private $input;

    /** @var resource */
    private $results;

    private ?InFlight $job = null;

    /**
     * @param  list<string>  $command
     */
    public function __construct(private readonly array $command)
    {
        $this->spawn();
    }

    public function busy(): bool
    {
        return $this->job !== null;
    }

    public function job(): ?InFlight
    {
        return $this->job;
    }

    /**
     * @return resource
     */
    public function results()
    {
        return $this->results;
    }

    public function send(Reader $reader, KafkaMessage $message, int $attempt, ?float $notBefore, float $timeout): void
    {
        $line = json_encode([
            'topic' => $message->topic_name,
            'partition' => $message->partition,
            'offset' => $message->offset,
            'key' => $message->key !== null ? base64_encode($message->key) : null,
            'value' => base64_encode((string) $message->payload),
            'attempt' => $attempt,
            'not_before' => $notBefore,
        ], JSON_THROW_ON_ERROR);

        if (@fwrite($this->input, $line."\n") === false) {
            throw new RuntimeException('slot process is gone');
        }

        $start = max(microtime(true), $notBefore ?? 0.0);
        $this->job = new InFlight($reader, $message, $attempt, $start + $timeout);
    }

    /**
     * Reads the outcome of the current message: null if the handler
     * succeeded, the error otherwise.
     */
    public function finish(): ?string
    {
        $line = fgets($this->results);
        if ($line === false) {
            $this->restart();

            return 'handler process exited';
        }
        $this->job = null;

        $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        return $result['error'] ?? null;
    }

    /**
     * Kills the handler in the middle of the message and starts a new one.
     */
    public function restart(): void
    {
        $this->job = null;
        $this->close();
        $this->spawn();
    }

    public function close(): void
    {
        proc_terminate($this->process, SIGKILL);
        fclose($this->input);
        fclose($this->results);
        proc_close($this->process);
    }

    private function spawn(): void
    {
        $process = proc_open($this->command, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR, 3 => ['pipe', 'w']], $pipes);
        if ($process === false) {
            throw new RuntimeException('cannot start slot process');
        }

        $this->process = $process;
        $this->input = $pipes[0];
        $this->results = $pipes[3];
    }
}
