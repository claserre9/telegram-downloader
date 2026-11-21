
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

## Quick Start with Docker
The easiest way to run Telegram Downloader on your own computer: you only need
[Docker](https://docs.docker.com/get-docker/) with Docker Compose (included in Docker Desktop on
Windows and macOS). PHP and Composer are not required.

1. **Create your own Telegram API credentials.** Sign in at https://my.telegram.org, open
   **API development tools** and create an application (any name and short name). Keep the
   `api_id` and `api_hash` it shows. Each person running the app uses their own credentials:
   do not share yours, and do not use someone else's.
2. **Get the code and configure it:**
   ```bash
   git clone https://github.com/claserre9/telegram-downloader.git
   cd telegram-downloader
   cp .env.dev .env
   ```
   Edit `.env`, uncomment `TELEGRAM_API_ID` and `TELEGRAM_API_HASH` and set your values.
3. **Start the web interface:**
   ```bash
   docker compose up -d --build
   ```
   Open http://localhost:8080 and scan the QR code from the Telegram app on your phone
   (**Settings → Devices → Link Desktop Device**). The first start takes a few minutes while
   the image is built.
4. **Or use the command line** (the QR code is shown in the terminal on the first run):
   ```bash
   docker compose run --rm telegram-downloader php bin/console tdm @channel_name
   ```
   Files are saved in `downloads/<chat>` next to `docker-compose.yml`. The
   [command options](#options) work the same way; with `--output`, use a folder inside
   `downloads/` (for example `-o downloads/videos`) so the files land on your computer.

Your Telegram sessions are kept in `sessions/` and your files in `downloads/`, on your computer,
so stopping, updating or rebuilding the container does not log you out.

| Task | Command |
|---|---|
| Stop | `docker compose down` |
| Update to the latest version | `git pull && docker compose up -d --build` |
| See the logs | `docker compose logs -f` |

Optional settings, also in `.env`:

| Variable | Default | Purpose |
|---|---|---|
| `WEB_PORT` | `8080` | Port of the web interface on your computer. |
| `DOCKER_UID`, `DOCKER_GID` | `1000` | Linux only: set them to the output of `id -u` and `id -g` if they differ, then rebuild, so files in `sessions/` and `downloads/` belong to you. |

**Security.** A file in `sessions/` gives full access to the Telegram account that logged in
with it. The web interface is therefore only reachable from your own computer (`127.0.0.1`):
do not publish the port on a network or the Internet, and do not share the `sessions/` folder.
To end a session, use **Logout** in the web interface, or delete `sessions/cli.session` for the
command line, and terminate it in Telegram under **Settings → Devices**.

**Troubleshooting.** When you cancel the command with Ctrl+C in Docker, MadelineProto may print
a stack trace after `Login cancelled.` or `Download interrupted.`: it is harmless, and the next
run resumes normally.

## Installation
To install Telegram Downloader without Docker (PHP 8.4 or later and Composer required), follow these steps:

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
   working directory (set `TELEGRAM_CLI_SESSION` in `.env` to keep it elsewhere; the Docker image
   uses `sessions/cli.session`). Treat that folder as a secret. Press Ctrl+C to cancel the login.
4. Every photo and file (videos, audio, voice notes, GIFs, documents) is downloaded, newest first,
   into `downloads/<chat>/`. Each file shows a progress bar; add `-v` to also list files that are
   already downloaded. The run ends with a summary and exits with an error code if any file failed.

### Options

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
it is safe to stop the command with Ctrl+C. Before each file, the command checks the free disk
space: when the file would leave less than 1 GB free, or the disk fills up during the download, it
stops and the next run resumes with that file. After a run limited by `--limit` or `--since`, a run
without them continues with the older messages.

#### Note

You can pass credentials directly with `TELEGRAM_CHANNEL=channel TELEGRAM_API_HASH=key php bin/console telegram:download:media`

If you set `variables_order = "EGPCS"` in `/etc/php/php.ini` (`E` is required for ENV).

## Web Interface (Slim v4 and Twig)
The lightweight web UI runs on [Slim v4](https://www.slimframework.com/) with [Twig](https://twig.symfony.com/) templates.
Run the built-in server with the Slim front controller:

```bash
composer serve
```

This runs `php -S localhost:8000 -t public public/index.php` with `PHP_CLI_SERVER_WORKERS=4`.
The built-in server otherwise handles one request at a time, so a long download would block
every other page until it finishes. To start it by hand on another port:

```bash
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8888 -t public public/index.php
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
Use the manual chat field if a conversation is not listed; public channels and groups can be
opened by `@username` without joining them. Then browse media by chat/channel,
filter by type, paginate, and download files. The media list shows only photos and files
(text messages and link previews are skipped), each with its Telegram thumbnail: a blurred
preview embedded in the message appears at once, then the 320 px thumbnail loads on demand
and is cached by the browser. Audio and plain files show a type icon instead. Downloads
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
