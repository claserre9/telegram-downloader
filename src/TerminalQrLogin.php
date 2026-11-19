<?php

declare(strict_types=1);

namespace claserre9;

use Amp\SignalException;
use Closure;
use danog\MadelineProto\API;
use danog\MadelineProto\RPCErrorException;
use RuntimeException;
use Throwable;

/**
 * Terminal login by QR code only, followed by the two-step verification password when the account has one.
 *
 * Telegram access is injected as closures so the flow can be tested without a network connection.
 */
final class TerminalQrLogin
{
    public const PASSWORD_ATTEMPTS = 3;

    /**
     * @param Closure(): int $getState Current MadelineProto login state (API::* constants).
     * @param Closure(): void $abandonPendingCodeLogin Drops a phone-code login left pending by an older version.
     * @param Closure(): ?string $firstQrCode Text of a new QR code, or null if Telegram needs no scan.
     * @param Closure(): ?string $waitForScan Waits for the scan; returns the text of a renewed QR code on expiry, or null once scanned.
     * @param Closure(string): void $completePassword Submits the two-step verification password.
     * @param Closure(string): void $write Writes a message for the user.
     * @param Closure(string): string $askPassword Asks for the password without echoing it.
     */
    public function __construct(
        private readonly Closure $getState,
        private readonly Closure $abandonPendingCodeLogin,
        private readonly Closure $firstQrCode,
        private readonly Closure $waitForScan,
        private readonly Closure $completePassword,
        private readonly Closure $write,
        private readonly Closure $askPassword,
    ) {
    }

    /**
     * @throws RuntimeException If the login cannot be completed.
     */
    public function run(): void
    {
        ($this->abandonPendingCodeLogin)();

        if (($this->getState)() === API::NOT_LOGGED_IN) {
            $qrText = ($this->firstQrCode)();
            if ($qrText !== null) {
                ($this->write)('Scan this QR code with Telegram on your phone: Settings → Devices → Link Desktop Device.');
            }
            while ($qrText !== null) {
                ($this->write)($qrText);
                $qrText = ($this->waitForScan)();
                if ($qrText !== null) {
                    ($this->write)('The QR code expired; scan this new one.');
                }
            }
        }

        for ($attempt = 1; ($this->getState)() === API::WAITING_PASSWORD; $attempt++) {
            $password = ($this->askPassword)('Two-step verification password');
            try {
                ($this->completePassword)($password);
            } catch (RPCErrorException $e) {
                // Matched on the RPC code: the specific subclass may not survive the IPC boundary.
                if ($e->rpc !== 'PASSWORD_HASH_INVALID') {
                    throw $e;
                }
                if ($attempt >= self::PASSWORD_ATTEMPTS) {
                    throw new RuntimeException('Incorrect two-step verification password. Run the command again to retry.');
                }
                ($this->write)('Incorrect password, try again.');
            }
        }

        if (($this->getState)() !== API::LOGGED_IN) {
            throw new RuntimeException('Telegram login was not completed.');
        }
    }

    /**
     * Whether a failure was caused by the user interrupting the command (Ctrl+C or a termination signal).
     */
    public static function wasInterrupted(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof SignalException) {
                return true;
            }
        }

        return false;
    }
}
