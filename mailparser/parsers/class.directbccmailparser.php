<?php

class DirectBccMailParser extends Parser {
	function parse($mailData) {
		if (!in_array('bcc', $mailData['received_via'])) { return false; }
		return [
			'direction' => 'out',
			'topic' => $mailData['subject'],
			'from' => $mailData['from'],
			'to' => $mailData['to'],
			'message' => $mailData['message'],
			'datetime' => $mailData['datetime'],
			'category_search' => [],
			'categories' => []
		];
	}

	function templateMarkdown($templateData) {
		if ($this->template === null) { $this->template = new MailTemplate(); }
		return $this->template->getMarkdown($templateData);
	}

	function generateDefaultConfig() {
		return [];
	}
}
