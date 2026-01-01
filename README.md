
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
2. Run the following command:
   ```
   php bin/console telegram:download:media
   ```
   or 

   ```
   php bin/console tdm
   ````
3. Follow the prompts to log in to Telegram.

## Web Interface (Slim v4)
The lightweight web UI now runs on [Slim v4](https://www.slimframework.com/).
Run the built-in server with the Slim front controller:

```bash
php -S localhost:8000 -t public public/index.php
```

Visit `http://localhost:8000`. Use the two-step login form (phone → code/2FA),
then browse media by chat/channel, filter by type, paginate, and download files.

### Example MadelineProto Usage
```php
$client = new WebTelegramClient($apiId, $apiHash, 'sessions/web.session');
$client->requestCode($phone);        // messages.sendCode
$client->completeCode($code);        // auth.signIn
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
