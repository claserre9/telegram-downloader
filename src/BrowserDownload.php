<?php

declare(strict_types=1);

namespace claserre9;

use Psr\Http\Message\ResponseInterface;
use Throwable;

final class BrowserDownload
{
    public const STREAMED_RESPONSE_HEADER = 'X-Telegram-Downloader-Streamed';

    /**
     * Run a download implementation that writes its response directly to PHP output.
     */
    public static function stream(ResponseInterface $response, callable $download): ResponseInterface
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        while (ob_get_level() > 0) {
            $status = ob_get_status();
            if (($status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE) === 0 || !ob_end_clean()) {
                break;
            }
        }

        $headersBeforeDownload = headers_list();
        $statusBeforeDownload = http_response_code();

        try {
            $download();
        } catch (Throwable $e) {
            if (headers_sent()) {
                error_log('Download interrupted after response started: ' . $e->getMessage());
                return $response->withHeader(self::STREAMED_RESPONSE_HEADER, '1');
            }

            if (headers_list() !== $headersBeforeDownload || http_response_code() !== $statusBeforeDownload) {
                $message = 'Download error: ' . $e->getMessage();
                foreach ([
                    'Accept-Ranges',
                    'Cache-Control',
                    'Content-Disposition',
                    'Content-Length',
                    'Content-Range',
                    'Content-Security-Policy',
                    'Content-Transfer-Encoding',
                    'Content-Type',
                    'Pragma',
                    'X-Content-Type-Options',
                ] as $header) {
                    header_remove($header);
                }
                http_response_code(500);
                header('Content-Type: text/plain; charset=UTF-8');
                header('Content-Length: ' . strlen($message));
                echo $message;
                flush();

                return $response->withHeader(self::STREAMED_RESPONSE_HEADER, '1');
            }

            $response->getBody()->write('Download error: ' . $e->getMessage());
            return $response->withStatus(500);
        }

        // Commit native headers even for HEAD or an empty/error response, so Slim cannot replace them.
        flush();

        return $response->withHeader(self::STREAMED_RESPONSE_HEADER, '1');
    }
}