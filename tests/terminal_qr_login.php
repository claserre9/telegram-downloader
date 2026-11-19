<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use claserre9\commands\DownloadMediaCommand;
use claserre9\TerminalQrLogin;
use danog\MadelineProto\API;
use danog\MadelineProto\RPCErrorException;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/**
 * Simulated Telegram account driving a TerminalQrLogin.
 *
 * @param list<string|null> $scans What each wait returns: a renewed QR text (expiry) or null (scanned).
 * @param list<string> $passwords Passwords typed by the user, in order.
 * @return array{run: Closure(): void, output: list<string>, prompts: list<string>, resets: int}
 */
$simulate = static function (int $initialState, array $scans = [null], bool $needsPassword = false, array $passwords = [], string $validPassword = 'secret', ?string $passwordError = null): array {
    $state = (object) ['value' => $initialState, 'output' => [], 'prompts' => [], 'resets' => 0];
    $login = new TerminalQrLogin(
        static fn (): int => $state->value,
        static function () use ($state): void {
            if (in_array($state->value, [API::WAITING_CODE, API::WAITING_SIGNUP], true)) {
                $state->value = API::NOT_LOGGED_IN;
                $state->resets++;
            }
        },
        static fn (): ?string => $state->value === API::NOT_LOGGED_IN ? 'QR#1' : null,
        static function () use ($state, &$scans, $needsPassword): ?string {
            $next = array_shift($scans);
            if ($next === null) {
                $state->value = $needsPassword ? API::WAITING_PASSWORD : API::LOGGED_IN;
            }
            return $next;
        },
        static function (string $password) use ($state, $validPassword, $passwordError): void {
            if ($passwordError !== null) {
                throw RPCErrorException::make($passwordError, 400, '');
            }
            if ($password !== $validPassword) {
                // An empty caller keeps MadelineProto from reporting the error to its servers.
                throw RPCErrorException::make('PASSWORD_HASH_INVALID', 400, '');
            }
            $state->value = API::LOGGED_IN;
        },
        static function (string $message) use ($state): void {
            $state->output[] = $message;
        },
        static function (string $prompt) use ($state, &$passwords): string {
            $state->prompts[] = $prompt;
            return array_shift($passwords) ?? '';
        },
    );

    return ['run' => $login->run(...), 'state' => $state];
};

// Already logged in: nothing is shown and nothing is asked.
$case = $simulate(API::LOGGED_IN);
($case['run'])();
$assert($case['state']->output === [] && $case['state']->prompts === [], 'An authorized session should start silently.');

// First scan succeeds.
$case = $simulate(API::NOT_LOGGED_IN);
($case['run'])();
$assert($case['state']->value === API::LOGGED_IN, 'Scanning the QR code should log in.');
$assert(str_contains($case['state']->output[0] ?? '', 'Link Desktop Device'), 'The terminal should explain where to scan.');
$assert(($case['state']->output[1] ?? null) === 'QR#1', 'The QR code should be printed.');
$assert(count($case['state']->output) === 2 && $case['state']->prompts === [], 'No password should be asked without 2FA.');

// The code expires twice before the scan: each renewed code is printed.
$case = $simulate(API::NOT_LOGGED_IN, ['QR#2', 'QR#3', null]);
($case['run'])();
$output = $case['state']->output;
$assert(in_array('QR#2', $output, true) && in_array('QR#3', $output, true), 'Renewed QR codes should be printed.');
$assert(count(array_filter($output, static fn (string $line): bool => str_contains($line, 'expired'))) === 2, 'Each expiry should be announced.');
$assert($case['state']->value === API::LOGGED_IN, 'A scan after renewals should log in.');

// 2FA: a wrong password is retried, then the right one is accepted.
$case = $simulate(API::NOT_LOGGED_IN, [null], true, ['wrong', 'secret']);
($case['run'])();
$assert($case['state']->value === API::LOGGED_IN, 'The correct password should complete the login.');
$assert(count($case['state']->prompts) === 2, 'A wrong password should be asked again.');
$assert(in_array('Incorrect password, try again.', $case['state']->output, true), 'A wrong password should be reported.');

// 2FA: three wrong passwords stop with a clear message.
$case = $simulate(API::NOT_LOGGED_IN, [null], true, ['a', 'b', 'c', 'd']);
try {
    ($case['run'])();
    $assert(false, 'Too many wrong passwords should fail.');
} catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), 'Incorrect two-step verification password'), 'The failure should explain the wrong password.');
}
$assert(count($case['state']->prompts) === TerminalQrLogin::PASSWORD_ATTEMPTS, 'The password should be asked at most ' . TerminalQrLogin::PASSWORD_ATTEMPTS . ' times.');

// 2FA: other Telegram errors are not mistaken for a wrong password.
$case = $simulate(API::NOT_LOGGED_IN, [null], true, ['secret'], 'secret', 'FLOOD_WAIT_X');
try {
    ($case['run'])();
    $assert(false, 'Other Telegram errors should propagate.');
} catch (RPCErrorException $e) {
    $assert($e->rpc !== 'PASSWORD_HASH_INVALID', 'Unrelated errors should be rethrown unchanged.');
}
$assert(count($case['state']->prompts) === 1, 'Unrelated errors should not trigger a password retry.');

// Resuming a session that stopped at the password step asks only for the password.
$case = $simulate(API::WAITING_PASSWORD, [], false, ['secret']);
($case['run'])();
$assert($case['state']->value === API::LOGGED_IN && count($case['state']->output) === 0, 'A pending password step should resume without a new QR code.');

// A phone-code login left by an older version is dropped in favor of the QR code.
$case = $simulate(API::WAITING_CODE);
($case['run'])();
$assert($case['state']->resets === 1 && in_array('QR#1', $case['state']->output, true), 'A pending phone-code login should be replaced by a QR login.');
$assert($case['state']->value === API::LOGGED_IN, 'The QR login should then complete.');

// A state the flow cannot handle fails instead of pretending to be logged in.
$case = $simulate(API::LOGGED_OUT);
try {
    ($case['run'])();
    $assert(false, 'An unsupported state should fail.');
} catch (RuntimeException $e) {
    $assert($e->getMessage() === 'Telegram login was not completed.', 'An unsupported state should report an incomplete login.');
}

// Ctrl+C surfaces as a SignalException somewhere in the exception chain.
$interrupted = new RuntimeException('Failed to start Madeline', 0, new LogicException('loop', 0, new Amp\SignalException('SIGINT received')));
$assert(TerminalQrLogin::wasInterrupted($interrupted), 'A signal anywhere in the chain should count as an interruption.');
$assert(!TerminalQrLogin::wasInterrupted(new RuntimeException('network down')), 'Ordinary failures are not interruptions.');

// The command's session lives at the project root whatever the working directory.
$expectedSession = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'session.madeline';
$previousDirectory = getcwd();
chdir(sys_get_temp_dir());
try {
    $assert(DownloadMediaCommand::sessionPath() === $expectedSession, 'The CLI session path should not depend on the working directory.');
} finally {
    chdir((string) $previousDirectory);
}
$assert(DownloadMediaCommand::sessionPath() === $expectedSession, 'The CLI session path should be stable.');

fwrite(STDOUT, "Terminal QR login tests passed.\n");
