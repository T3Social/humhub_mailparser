<?php

abstract class Parser {
	protected $parameters;
	protected $config;
	protected $mailparser;
	protected $template;

	public final function __construct($mailparser) {
		$this->mailparser = $mailparser;
		$this->config = $this->generateDefaultConfig();
	}

	abstract public function parse($mailData);
	abstract public function templateMarkdown($templateData);
	abstract public function generateDefaultConfig();

	public final function setConfig($newConfig) {
		$this->config = array_replace_recursive($this->config, $newConfig);
	}
}
