<?php

class ForwardedMailParser extends Parser {

	function parse($mailData) {
		$mailMatch = [];
		preg_match_all($this->config['regexMail'], $mailData['message'], $mailMatch, PREG_SET_ORDER, 0);

		// Mail did not match the forwarding-pattern, so no match - return false
		if (count($mailMatch) != 1) { return false; }

		$parsedResponse = [];

		$mail = $mailMatch[0];
		$mail = array_filter($mail, function($key) { return !is_int($key); }, ARRAY_FILTER_USE_KEY);


		// Topic and Message
		$parseResponse['topic'] = trim($mail['topic']);
		$parseResponse['message'] = trim($mail['message']);

		$parseResponse['from'] = $this->mailparser->sanitizeStakeholder($mail['sender_name'], $mail['sender_mail']);



		// Mail type
		$parseResponse['direction'] = preg_match($this->config['regexSelf'], $mail['sender_mail']) === 1 ? 'out' : 'in';


		// Categories
		$mail['category_search'] = trim($mail['category_search']);
		$parseResponse['category_search'] = '';
		$parseResponse['categories'] = [];
		if ($mail['category_search']) {
			$parseResponse['category_search'] = $mail['category_search'];
			foreach ($this->mailparser->getCategories() as $guid => $name) {
				$score = $this->mailparser->stringComparer->compare($mail['category_search'], $name);

				if ($score < .83) { continue; }

				$parseResponse['categories'][$guid] = $name;
			}
		}

		// Receipients
		$receipients = [];
		$receipientMatches = [];
		preg_match_all($this->config['regexReceipients'], trim($mail['receipients_plain']), $receipientMatches, PREG_SET_ORDER);
		foreach ($receipientMatches as $key => $value) {
			$receipients[] = $this->mailparser->sanitizeStakeholder($value['name'], $value['mail']);
		}
		$parseResponse['to'] = $receipients;


		// Date and Time
		$parseResponse['datetime'] = $this->mailparser->sanitizeDateTime($mail['datetime']);

		return $parseResponse;
	}

	function templateMarkdown($templateData) {
		if ($this->template === null) { $this->template = new MailTemplate(); }
		return $this->template->getMarkdown($templateData);
	}

	function generateDefaultConfig() {
		return [
			'regexMail' => '/(?<category_search>.*?(?=[\r\n])).*?[-]+\s{0,1}(?:Forwarded message|Weitergeleitete Nachricht)\s{0,1}[-]+[\r\n]+\s*(?:From|Von):\s*(?<sender_name>.*?(?=\s*<))\s*<(?<sender_mail>.*?(?=>))>[\r\n]+\s*(?:Date|Datum):\s*(?<datetime>.*?(?=[\r\n]))[\r\n]+\s*(?:Subject|Topic|Betreff):\s*(?<topic>.*?(?=[\r\n]))[\r\n]+\s*(?:To|An):\s*(?<receipients_plain>.*?(?=[\r\n]))[\r\n]+(?<message>.*)/ms',
			'regexReceipients' => '/,*\s*(?<name>.*?(?=\s*<)){0,1}\s*<(?<mail>.*?(?=>))>/m',
			'regexSelf' => '//'
		];
	}
}
