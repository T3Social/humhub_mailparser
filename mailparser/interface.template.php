<?php

abstract class Template {
	public final function __construct() { }

	abstract public function getMarkdown($templateData);

	public final function markdownMailShortener($name, $mail) {
		$name = trim($name); $mail = trim($mail);
		return '[' . (strlen($name) > 0 ? $name : substr($mail, 0, strpos($mail, "@"))) . '](mailto:' . $mail . ')';
	}
}
