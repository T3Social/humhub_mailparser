<?php

class WhatsAppParser extends Parser {
	function parse($mailData) {
		$parseResponse = [];

		$subjectResults;
		preg_match_all('/^\s*whatsapp\s*(?<category_search>.*)/i', $mailData['subject'], $subjectResults, PREG_SET_ORDER);
		if (count($subjectResults)<=0) { return false; }
		$subjectResults = $subjectResults[0];

		$messageResults;
		preg_match_all('/\[(?<day>\d{1,2})\.(?<month>\d{1,2})\.,\s(?<hour>\d{1,2}):(?<minute>\d{1,2})\]\s*(?<author>.*?(?=:)):\s*(?<message>.*?(?=\s*(?:\[|$)))/s', $mailData['message'], $messageResults, PREG_SET_ORDER);
		if (count($messageResults)<=0) { return false; }


		// Categories
		$subjectResults['category_search'] = trim($subjectResults['category_search']);
		$parseResponse['category_search'] = '';
		$parseResponse['categories'] = [];
		if ($subjectResults['category_search']) {
			$parseResponse['category_search'] = $subjectResults['category_search'];
			foreach ($this->mailparser->getCategories() as $guid => $name) {
				$score = $this->mailparser->stringComparer->compare($subjectResults['category_search'], $name);

				if ($score < .83) { continue; }

				$parseResponse['categories'][$guid] = $name;
			}
		}

		$participants = [];
		$messages = [];
		foreach ($messageResults as $message) {
			$message['author'] = trim($message['author']);
			if (!in_array($message['author'], $participants)) {
				$participants[] = $message['author'];
			}

			$messages[] = [
				'author' => $message['author'],
				'text' => $message['message'],
				'datetime' =>  $this->mailparser->sanitizeDateTime(date('Y') . '-' . $message['month'] . '-' . $message['day'] . ' ' . $message['hour'] . ':' . $message['minute'] . ':00')
			];
		}

		$parseResponse['platform'] = 'WhatsApp';
		$parseResponse['participants'] = $participants;
		$parseResponse['messages'] = $messages;
		$parseResponse['datetime'] = $messages[0]['datetime'];

		return $parseResponse;
	}

	function templateMarkdown($templateData) {
		if ($this->template === null) { $this->template = new ImTemplate(); }
		return $this->template->getMarkdown($templateData);
	}

	function generateDefaultConfig() {
		return [];
	}
}
