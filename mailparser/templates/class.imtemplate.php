<?php

class ImTemplate extends Template { function getMarkdown($templateData) {
	$chat = '';
	foreach ($templateData['messages'] as $message) {
		$chat .= '**' . date('H:i', strtotime($message['datetime'])) . ', ' . $message['author'] . ':** ' . $message['text'] . PHP_EOL;
	}
	return sprintf(
		'💬 **%s**  |  **Unterhaltung zwischen:** %s%s%s',
		$templateData['platform'],
		implode(', ', $templateData['participants']),
		PHP_EOL . PHP_EOL,
		$chat
	);
} }
