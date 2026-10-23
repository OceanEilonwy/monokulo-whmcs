<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * The gateway's log: every line goes to WHMCS with the request's trace id
 * (see Whmcs::log for where), and warnings and errors are also kept (up to
 * 20 a request) for forwarding to Monokulo when the merchant turned that on.
 */
final class Logger {
	public const MAX_FORWARDED = 20;

	/** @var list<array{level: string, message: string, trace_id: string}> */
	private array $forward = [];

	/**
	 * @param \Closure(string, string): void $write writes one line (level, line) to WHMCS's logs
	 */
	public function __construct(
		private readonly \Closure $write,
		private readonly Trace $trace,
	) {
	}

	public function info(string $message): void {
		$this->log('info', $message);
	}

	public function warning(string $message): void {
		$this->log('warning', $message);
	}

	public function error(string $message): void {
		$this->log('error', $message);
	}

	/** @return list<array{level: string, message: string, trace_id: string}> */
	public function forwardable(): array {
		return $this->forward;
	}

	private function log(string $level, string $message): void {
		($this->write)($level, strtoupper($level) . ' ' . $message . ' [trace ' . $this->trace->id() . ']');
		if ($level !== 'info' && count($this->forward) < self::MAX_FORWARDED) {
			$this->forward[] = ['level' => $level, 'message' => $message, 'trace_id' => $this->trace->id()];
		}
	}
}
