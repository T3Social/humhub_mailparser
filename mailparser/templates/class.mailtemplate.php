<?php

class MailTemplate extends Template { function getMarkdown($templateData) {
	$receipientString = '';
	foreach ($templateData['to'] as $receipient) {
		$receipientString .= $this->markdownMailShortener($receipient['name'], $receipient['mail']) . ', ';
	}
	$receipientString = substr($receipientString, 0, -2);

	return sprintf(
		'%s  |  **Von:** %s  |  **Empfänger:** %s%s--- **Betreff:** %s  ---%s%s',
		($templateData['direction'] == 'in' ? '📥 **IN**' : '📤 **OUT**'), // Icon
		$this->markdownMailShortener($templateData['from']['name'], $templateData['from']['mail']),
		$receipientString,
		PHP_EOL . PHP_EOL,
		$templateData['topic'],
		PHP_EOL . PHP_EOL,
		$templateData['message']
	);
} }
