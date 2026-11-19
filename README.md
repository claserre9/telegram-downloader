
# Telegram Downloader

## Introduction
This project is a Telegram Downloader tool, designed to help users download any media (videos, photos and documents) from Telegram channels efficiently. 
It is particularly useful for channels that do not provide direct access to download media. The tool supports both public and private channels, provided that the user has appropriate access permissions.

## Key Benefit
Bypass Media Download Restrictions: One of the key features of this tool is its ability to download media from channels 
that have restrictions on direct media downloads. This functionality extends the accessibility of content, making it a valuable asset for users 
who need access to these media for various legitimate purposes.

## Features
- Download media from any Telegram channel (public or private).
- User-friendly: Easy to set up and use.
- Customizable download options, including video quality and save location.

## Prerequisites
Before you begin, ensure you have met the following requirements:
- You have a Telegram account with API access.
- You have access to the Telegram channel(s) from which you wish to download media.

## Installation
To install Telegram Downloader, follow these steps:

1. Clone the repository:
   ```
   git clone https://github.com/claserre9/telegram-downloader.git
   ```
2. Navigate to the project directory:
   ```
   cd telegram-downloader
   ```
3. Install the necessary dependencies:
   ```
   composer install
   ```

## Usage
To use Telegram Downloader, follow these steps:

1. Configure your Telegram API credentials in a `.env` file. Create it in the root project and add the necessary 
   environment variables (Check the .env.dev as an example).
2. Run the command with the chat to download from (or set `TELEGRAM_CHANNEL` in `.env`):
   ```
   php bin/console tdm @my_channel
   ```
   (`tdm` is short for `telegram:download:media`; the chat can be `@username`, a `t.me` link or an ID.)
3. On first run, a QR code is printed in the terminal. Scan it from Telegram on your phone
   (**Settings → Devices → Link Desktop Device**), then enter your two-step verification
   password if asked (3 attempts). Phone-number and bot-token logins are not offered.
   The login is saved in `session.madeline` at the project root, so later runs reuse it from any
   working directory. Treat that folder as a secret. Press Ctrl+C to cancel the login.
4. Every photo and file (videos, audio, voice notes, GIFs, documents) is downloaded, newest first,
   into `downloads/<chat>/`. Each file shows a progress bar; add `-v` to also list files that are
   already downloaded. The run ends with a summary and exits with an error code if any file failed.

Options:

| Option | Effect |
|---|---|
| `-t, --type=photo\|video\|audio\|document` | Only this media type (`document` = any file, including videos and audio sent as files) |
| `-o, --output=DIR` | Download directory (relative to the current directory) |
| `-l, --limit=N` | Stop after N files, newest first |
| `-s, --since=DATE` | Only messages from this date, e.g. `2024-01-31` or `"7 days ago"` |
| `--rescan` | Scan the whole history again, e.g. after deleting files you want back |

Progress is saved in the download directory (`.telegram-downloader*.json`, one file per `--type`).
The next run only scans messages posted since, resumes an interrupted run where it stopped, and
retries files that failed. Files already complete on disk are skipped and partial files resume, so
it is safe to stop the command with Ctrl+C. After a run limited by `--limit` or `--since`, a run
without them continues with the older messages.

#### Note

You can pass credentials directly with `TELEGRAM_CHANNEL=channel TELEGRAM_API_HASH=key php bin/console telegram:download:media`

If you set `variables_order = "EGPCS"` in `/etc/php/php.ini` (`E` is required for ENV).

## Web Interface (Slim v4 and Twig)
The lightweight web UI runs on [Slim v4](https://www.slimframework.com/) with [Twig](https://twig.symfony.com/) templates.
Run the built-in server with the Slim front controller:

```bash
php -S localhost:8000 -t public public/index.php
```

Visit `http://localhost:8000` and log in by QR code: open Telegram on your phone, go to
**Settings → Devices → Link Desktop Device**, and scan the code shown on the page. The page
refreshes automatically and renews the code when it expires. Accounts with two-step
verification then enter their password on a separate step. Phone-number login codes are not
supported, because Telegram often does not deliver them to third-party apps.
Then search and paginate through conversations, groups and channels from the account. The
conversation list includes archived chats, is cached in the web session while navigating,
and can be refreshed explicitly; the first load may take longer for accounts with many chats.
HTTP actions and presentation data are handled in `src/WebController.php`; the shared page
layout and individual login, conversation, and media views live in `templates/`. Twig's HTML
auto-escaping is enabled for rendered values. `public/index.php` is kept as the Slim front
controller for dependency setup, route registration, static CSS, and streamed downloads.
Each browser uses its own MadelineProto session in `sessions/`. A `tg_session` cookie (HttpOnly,
30 days, renewed on every visit) links the browser to that session, so the login survives a
browser or server restart and the expiry of the PHP session; it is only trusted while the
Telegram session is still authorized. Logging out revokes the Telegram authorization, stops the
session's worker, deletes its files and clears the cookie. Existing PHP sessions that were
already logged in before per-browser sessions continue to use `sessions/web.session`.
Treat all files in `sessions/` as secrets and do not share or expose them.
Use the manual chat field if a conversation is not listed. Then browse media by chat/channel,
filter by type, paginate, and download files. Downloads
are streamed from Telegram to the browser as chunks, so they can begin before the complete
media file has been saved on the server. The transfer speed still depends on Telegram and
the connection. PHP output buffering or a reverse proxy may need to be disabled/configured
to pass chunks through immediately.

### Example MadelineProto Usage
```php
$client = new WebTelegramClient($apiId, $apiHash, 'sessions/web-<id>.session');
$qr = $client->qrLogin();             // auth.exportLoginToken; poll until status is not 'pending'
$client->completePassword($password); // only if qrLogin() returned status 'password'
$messages = $client->getMedia($chat); // messages.getHistory
```

## Contributing to Telegram Downloader
To contribute to Telegram Downloader, follow these steps:

1. Fork this repository.
2. Create a branch: `git checkout -b <branch_name>`.
3. Make your changes and commit them: `git commit -m '<commit_message>'`.
4. Push to the original branch: `git push origin <project_name>/<location>`.
5. Create the pull request.

Alternatively, see the GitHub documentation on [creating a pull request](https://docs.github.com/en/github/collaborating-with-issues-and-pull-requests/creating-a-pull-request).

## Contributors
Thanks to the following people who have contributed to this project:

- [@claserre9](https://github.com/claserre9)

## Contact
If you want to contact me, you can reach me at claserre9@gmail.com.

## License
This project uses the following license: MIT.
