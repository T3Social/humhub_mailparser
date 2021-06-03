<?php

include 'class.request.php';
include 'class.stringcompare.php';

include 'interface.template.php';
include 'interface.parser.php';

class MailParser {
	protected $imap;
	protected $config = [
		'apiPath' => null,
		'apiCredentials' => [
			'username' => false,
			'password' => false
		],
		'parserMail' => '',
		'knownReceipients' => [],
		'parserConfig' => [],
		'directMessage' => true, // Shall script treat non-forwarded messages from inbound-validated senders as outgoing mail?
		'deleteMode' => 'pushed', // never, parsed, pushed, all
		'jsonResponse' => true, // shall the script return json encoded process info?
		'logfile' => 'scriptresponse.log', // null, false or empty to disable logging

	];
	public $stringComparer;

	protected $categories = false;

	protected $filters = [];

	protected $parsers = [];

	public function __construct($newImap, $newConfig = []) {
		$this->imap = $newImap;
		$this->stringComparer = new StringCompare();

		if (!array_key_exists('parserMail', $newConfig)) {
			throw new Error('Providing an mail-address for the mail parser via config option "parserMail" is mandatory.');
		}

		foreach (glob(dirname(__FILE__) . '/templates/class.*.php') as $templateFile) include $templateFile;

		$classes = get_declared_classes();
		foreach (glob(dirname(__FILE__) . '/parsers/class.*.php') as $parserFile) {
			include $parserFile;
			$diff = array_diff(get_declared_classes(), $classes);
			$parserClassName = reset($diff);
			$classes[] = $parserClassName;

			$this->parsers[$parserClassName] = new $parserClassName($this);
			$this->config['parserConfig'][$parserClassName] = $this->parsers[$parserClassName]->generateDefaultConfig();
		}

		$this->config = array_replace_recursive($this->config, $newConfig);

		foreach ($this->parsers as $name => $parser) {
			$parser->setConfig($this->config['parserConfig'][$name]);
		}
	}



	public function run() {
		// Setting up default script response
		$response = [ 'success' => false, 'code' => 0, 'code_message' => 'Script exited in an unstable state', 'data' => [] ];

		// Check for new mails in mailbox
		$mailcheck = imap_check($this->imap);

		// Exit early if there are no mails on server
		if ($mailcheck->Nmsgs == 0) {
			return array_replace_recursive($response, [
				'success' => true,
				'code' => 1,
				'code_message' => 'No mails on server to process.'
			]);
		}

		// Fetch overview of all messages on server
		$imapMails = imap_fetch_overview($this->imap, '1:' . $mailcheck->Nmsgs, 0);

		$mails = [];
		foreach ($imapMails as $imapMail) {
			$mails[] = $this->processMail($imapMail);
		}

		imap_expunge($this->imap);

		return array_replace_recursive($response, [
			'success' => true,
			'code' => 2,
			'code_message' => 'Mails on server processed.',
			'data' => $mails
		]);
	}


	protected function processMail($imapMail) {
		$mailResponse = [
			'message_plain' => '',
			'message_html' => '',
			'attachements' => []
		];

		// Requesting structure of this particular single mail
		$structure = imap_fetchstructure($this->imap, $imapMail->msgno);
		$header = imap_headerinfo($this->imap, $imapMail->msgno);

		if (property_exists($structure, 'parts')) { // Mail has multiple parts to cycle through
			foreach ($structure->parts as $partno => $part) {
				$mailResponse = $this->getMailPart($mailResponse, $imapMail, $part, $partno + 1);
			}
		} else {  // Simple mail, has no parts
			$mailResponse = $this->getMailPart($mailResponse, $imapMail, $structure, 0);
		}

		$mailResponse['message_html'] = trim($mailResponse['message_html']);
		$mailResponse['message_plain'] = trim($mailResponse['message_plain']);

		$mailResponse['message'] = strlen($mailResponse['message_html']) > 0 ? $this->removeMessageHTML($mailResponse['message_html']) : $mailResponse['message_plain'];

		$receivedVia = [];

		$receipients = [];
		foreach ($header->to as $receipient) {
			if ($receipient->mailbox == 'undisclosed-recipients') { continue; }
			if ($receipient->mailbox . '@' . $receipient->host == $this->config['parserMail']) { $receivedVia[] = 'to'; continue; }
			$receipients[] = $this->sanitizeStakeholder(property_exists($receipient, 'personal') ? $receipient->personal : '', $receipient->mailbox . '@' . $receipient->host);
		}

		$receipientsCC = [];
		if (property_exists($header, 'cc')) {
			foreach ($header->cc as $receipient) {
				if ($receipient->mailbox . '@' . $receipient->host == $this->config['parserMail']) { $receivedVia[] = 'cc'; continue; }
				$receipientsCC[] = $this->sanitizeStakeholder(property_exists($receipient, 'personal') ? $receipient->personal : '', $receipient->mailbox . '@' . $receipient->host);
			}
		}

		if (count($receivedVia) == 0) { $receivedVia[] = 'bcc'; }


		$sender = $this->sanitizeStakeholder($header->from[0]->personal, $header->from[0]->mailbox . '@' . $header->from[0]->host);

		$subject = imap_mime_header_decode($imapMail->subject);
		$subject = $subject[0]->text;

		// Adding basic inormtion about the incoming mail
		$mailResponse = array_merge($mailResponse, [
			'id' => $imapMail->msgno,
			'datetime' => $this->sanitizeDateTime($imapMail->date),
			'subject' => $subject,
			'from' => $sender,
			'to' => $receipients,
			'cc' => $receipientsCC,
			'received_via' => $receivedVia,
			'submitted' => false,
			'deleted' => false,
		]);

		$mailResponse['parse'] = $this->parseMail($mailResponse);

		$mailResponse['push_data'] = false;
		$mailResponse['submitted'] = false;

		if ($this->hasFilter(['MESSAGE_PUSH_EXACT_MATCH', 'MESSAGE_PUSH_MATCH', 'MESSAGE_PUSH_NO_MATCH'])) {
			$pushData = [ 'categories' => [], 'parser' => [] ];
			foreach ($mailResponse['parse']['data'] as $parserName => $parserData) {
				if (array_key_exists('categories', $parserData)) {
					$pushData['categories'] = array_merge($pushData['categories'], $parserData['categories']);
				}
				$pushData['parser'][$parserName]['markdown'] = $this->parsers[$parserName]->templateMarkdown($parserData);
				$pushData['parser'][$parserName]['datetime'] = $mailResponse['parse']['data'][$parserName]['datetime'];
			}

			$mailResponse['push_data'] = $pushData;

			$pushResponses = [];
			if ($mailResponse['parse']['code'] == 1) { $pushResponses = array_merge($pushResponses, $this->applyFilter('MESSAGE_PUSH_EXACT_MATCH', $pushData, true)); }
			if ($mailResponse['parse']['code'] >= 1) { $pushResponses = array_merge($pushResponses, $this->applyFilter('MESSAGE_PUSH_MATCH', $pushData, true)); }
			if ($mailResponse['parse']['code'] == 0) { $pushResponses = array_merge($pushResponses, $this->applyFilter('MESSAGE_PUSH_NO_MATCH', $pushData, true)); }


			$success = false;
			foreach ($pushResponses as $response) {
				if ($response['response'] !== false) { $success = true; }
			}

			$mailResponse['submitted'] = $success ? $pushResponses : false;
		}

		$mailRespons['deleted'] = false;

		if (
			$this->config['deleteMode'] == 'all' ||
			($this->config['deleteMode'] == 'pushed' && $mailResponse['submitted'] !== false) ||
			($this->config['deleteMode'] == 'parsed' && $mailResponse['parse']['code'] >= 1)
		) {
			$mailResponse['deleted'] = true;
			imap_delete($this->imap, $mailResponse['id']);
		}


		$mailResponse = array_merge(array_flip(array('id', 'datetime', 'subject', 'from', 'to', 'cc', 'received_via', 'message_plain', 'message_html', 'message', 'attachements', 'parse', 'push_data', 'submitted', 'deleted')), $mailResponse);
		return $mailResponse;
	}


	protected function getMailPart($mailResponse, $imapMail, $part, $partno) {

	    // DECODE DATA: Multipart or Simple
	    $data = ($partno) ? imap_fetchbody($this->imap, $imapMail->msgno, $partno) : imap_body($this->imap, $imapMail->msgno);
		switch ($part->encoding) { case 1: $data = imap_8bit($data); break; case 3: $data = imap_base64($data); break; case 4: $data = imap_qprint($data); break; }



	    // PARAMETERS
	    // get all parameters, like charset, filenames of attachments, etc.
		$parameters = [];
	    if (property_exists($part, 'parameters')) { foreach ($part->parameters as $parameter) { $parameters[strtolower($parameter->attribute)] = $parameter->value; } }
	    if (property_exists($part, 'dparameters')) { foreach ($part->dparameters as $dparameter) { $parameters[strtolower($dparameter->attribute)] = $dparameter->value; } }



	    // ATTACHMENT (any part with a filename is an attachment, so an attached text file (type 0) is not mistaken as the message)
	    if (array_key_exists('filename', $parameters) || array_key_exists('name', $parameters)) {
	        // filename may be given as 'Filename' or 'Name' or both
	        $filename = ($parameters['filename']) ? $parameters['filename'] : $parameters['name'];

			$filenameDecode = imap_mime_header_decode($filename);
			$filenameDecode = $filenameDecode[0]->text;

	        // filename may be encoded, so see imap_mime_header_decode()
	        $mailResponse['attachements'][$filenameDecode] = '$data';  // this is a problem if two files have same name
	    }



	    // MESSAGE AND EMBEDDED MESSAGE
	    if ($part->type == 0 && $data) {
			if (strtolower($part->subtype) == 'plain') {
				$mailResponse['message_plain'] .= trim($data) . "\n\n";
			} else {
				$mailResponse['message_html'] .= trim($data) . "\n\n";
			}
	        //$charset = $parameters['charset'];
	    } else if ($part->type == 2 && $data) {
	        $mailResponse['message'] .= trim($data) . "\n\n";
	    }


	    // SUBPART RECURSION
	    if (property_exists($part, 'parts')) {
	        foreach ($part->parts as $partnoSub => $partSub) {
	            $mailResponse = $this->getMailPart($mailResponse, $imapMail, $partSub, $partno . '.' . ($partnoSub + 1));
			}
	    }

		return $mailResponse;
	}

	protected function parseMail($mailData) {
		$parsingResults = [];

		foreach ($this->parsers as $name => $parser) {
			$parse = $parser->parse($mailData);
			if (is_array($parse)) { $parsingResults[$name] = $parse; }
		}

		if (count($parsingResults) > 1) {
			return [
				'code' => 2,
				'code_message' => 'Multiple parsers matched the content (' . implode(', ', array_keys($parsingResults)) . ').',
				'data' => $parsingResults
			];
		} else if (count($parsingResults) < 1) {
			return [
				'code' => 0,
				'code_message' => 'No parsers matched the content',
				'data' => []
			];
		}

		return [
			'code' => 1,
			'code_message' => 'One parser matched the content (' . array_keys($parsingResults)[0] . ').',
			'data' => $parsingResults
		];

		return $parseResponse;
	}


	public function getCategories() {
		if ($this->categories !== false) { return $this->categories; }

		$this->categories = [
			'xyz' => 'Internes Projekt'
		];

		$this->categories = $this->applyFilter('GENERATE_CATEGORIES', $this->categories);

		return $this->categories;
	}

	public function registerFilter($name, $function) {
		if (array_key_exists($name, $this->filters)) {
			$this->filters[$name][] = $function;
		} else {
			$this->filters[$name] = [ $function ];
		}
	}

	protected function hasFilter($search) {
		if (is_string($search)) {
			return array_key_exists($name, $this->filters);
		} else if (is_array($search)) {
			$success = false;
			foreach ($search as $searchSingle) {
				$success = array_key_exists($searchSingle, $this->filters) ? true : $success;
			}

			return $success;
		}

		return false;
	}

	protected function applyFilter($name, $data, $accumulateData = false) {
		$dataSet = [];
		if (array_key_exists($name, $this->filters)) {
			foreach ($this->filters[$name] as $filterFunction) {
				$data = $filterFunction($data);
				if ($accumulateData === true) { $dataSet[] = $data; }
			}
		}

		if ($accumulateData === true) { return $dataSet; }
		return $data;
	}

	public function sanitizeStakeholder($name, $mail) {
		if (array_key_exists($mail, $this->config['knownReceipients'])) {
			$name = $this->config['knownReceipients'][$mail];
		}

		return [ 'name' =>$name, 'mail' => $mail ];
	}

	public function sanitizeDateTime($datetime) {
		$datetime = trim($datetime);

		try {
			$dateTimeObject = new DateTime($datetime);
		} catch(Exception $e1) {
			try {
				$datetime = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß\.,: ]/', '', $datetime);
				$datetime = str_replace([
					'Mo., ', 'Di., ', 'Mi., ', 'Do., ', 'Fr., ', 'Sa., ', 'So., ',
					'Mon., ', 'Tue., ', 'Wed., ', 'Thu., ', 'Fri., ', 'Sat., ', 'Sun., ',
					'Tu., ', 'We., ', 'Th., ', 'Su., ', 'um', 'at', 'Uhr'
				], '', $datetime);
				$datetime = str_replace([
					'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
					'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'
				], [
					'01.', '02.', '03.', '04.', '05.', '06.', '07.', '08.', '09.', '10.', '11.', '12.', '01.', '02.', '03.', '04.', '05.', '06.', '07.', '08.', '09.', '10.', '11.', '12.'
				], $datetime);
				$datetime = preg_replace('/\.\s*/', '.', $datetime);
				$datetime = preg_replace('/\s{2,}/', ' ', $datetime);
				$datetime = trim($datetime);
				$dateTimeObject = new DateTime($datetime);
			} catch(Exception $e2) {
				return null;
			}
		}

		return $dateTimeObject->format(DateTime::ATOM);
	}

	public function removeMessageHTML($message) {
		$message = str_replace(['<br>', '<br/>', '<br />', '<div>'], "\r\n", $message);
		$message = strip_tags($message);
		$message = html_entity_decode($message);
		$message = preg_replace("/(\r\n){3,}/", "\r\n", $message);
		return $message;
	}
}
