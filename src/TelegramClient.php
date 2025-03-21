<?php

namespace claserre9;

use danog\MadelineProto\API;
use danog\MadelineProto\Exception;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\SettingsAbstract;

/**
 *
 */
class TelegramClient
{
	private string $telegramApiId;
	private string $telegramApiHash;
	
	private ?API $madeline;
	private ?Settings $settings;
	
	/**
	 * @param string $telegramApiId
	 * @param string $telegramApiHash
	 */
	public function __construct(string $telegramApiId, string $telegramApiHash)
	{
		$this->telegramApiId = $telegramApiId;
		$this->telegramApiHash = $telegramApiHash;
		
		$this->setClientSettings();
		$this->init($this->settings);
		
	}
	
	/**
	 * @return void
	 */
	private function setClientSettings(): void
	{
		$this->settings = new Settings();
		
		$this->settings->setAppInfo( (new AppInfo())
			->setApiId($this->telegramApiId)
			->setApiHash($this->telegramApiHash)
		);
		
	}
	
	
	/**
	 * @param SettingsAbstract $settings
	 *
	 * @return void
	 */
	private function init(SettingsAbstract $settings): void
	{
		try {
			$this->madeline = new API('session.madeline', $settings);
		} catch (Exception $e) {
			exit($e->getMessage() . PHP_EOL);
		}
	
	}
	
	
	/**
	 * @return void
	 */
	public function start(): void
	{
		if(!$this->madeline){
			exit('Madeline not initialized.');
		}
		$this->madeline->start();
	}
	
	/**
	 * @return API|null
	 */
	public function getMadeline(): ?API
	{
		return $this->madeline;
	}
}