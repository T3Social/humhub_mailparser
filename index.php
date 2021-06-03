<?php header('Content-Type: application/json');

$environment = parse_ini_file('environment.ini.php', true);
$environment = $environment['environment'];

// Load config file from environments directory
$config = parse_ini_file('environments/' . $environment . '.ini.php', true);

// Load known mails from known_mails configuration file
$knownMails = [];
$kmi = parse_ini_file('known_mails.ini.php', true);
foreach ($kmi['known_mails'] as $km) { $kmp = explode(':', $km, 2); if (count($kmp)!=2) { continue; } $knownMails[$kmp[0]] = $kmp[1]; }



include 'mailparser/class.mailparser.php';

// Define HumHub API Paths
$humhubApiPaths = [
	'getSpaces' => 'api/v1/space',
	'getContainers' => 'api/v1/content/container',
	'postMessage' => 'api/v1/post/container'
];

// Establish a new IMAP connection
$mailbox = imap_open('{' . $config['imap']['address'] . ':993/imap/ssl}INBOX', $config['imap']['username'], $config['imap']['password']) or die ('Could not establish IMAP connection: ' . imap_last_error());

// Create an instance of the actual mailparser and passing anonymous config
$parser = new MailParser($mailbox, [
	'parserMail' => $config['imap']['mail'],
	'parserConfig' => [
		'ForwardedMailParser' => [
			'regexSelf' => $config['classification']['self']
		]
	],
	'knownReceipients' => $knownMails
]);

// Create function for loading categories from API and hook it into the parser
$parser->registerFilter('GENERATE_CATEGORIES', function($cats) use ($config, $humhubApiPaths) {
	$spaceRequest = new Request('GET', $config['api']['basepath'] . $humhubApiPaths['getSpaces'], $config['api_credentials']['default']);

	$spacesRaw = $spaceRequest->process();
	if (!$spacesRaw || !array_key_exists('results', $spacesRaw)) { return []; }

	$spacesRaw = $spacesRaw['results'];

	$categoriesResponse = [];
	foreach ($spacesRaw as $key => $space) {
		$categoriesResponse[$space['guid']] = $space['name'] . ' (' . $space['description'] . ')';
	}

	return $categoriesResponse;
});


// Available Filters: MESSAGE_PUSH_EXACT_MATCH, MESSAGE_PUSH_MATCH, MESSAGE_PUSH_NO_MATCH
// Provide function to push message to API
$parser->registerFilter('MESSAGE_PUSH_EXACT_MATCH', function($pushData) use ($config, $humhubApiPaths) {
	$targetContainer = $config['api']['default_container'];

	if (count($pushData['categories']) == 1) {
		$containerRequest = new Request('GET', $config['api']['basepath'] . $humhubApiPaths['getContainers'], $config['api_credentials']['default']);
		$containers = $containerRequest->process();
		if ($containers && array_key_exists('results', $containers)) {
			foreach ($containers['results'] as $container) if (array_key_exists($container['guid'], $pushData['categories'])) $targetContainer = $container['id'];
		}
	}

	$parser = key($pushData['parser']);
	$data = reset($pushData['parser']);

	switch ($parser) {
		case 'ForwardedMailParser':
			$target = $config['api']['basepath'] . $humhubApiPaths['postMessage'] . '/' . $targetContainer;
			$request = new Request('POST', $target, $config['api_credentials']['mailbot'], [ 'data' => [ 'message' => $data['markdown'], 'datetime' => $data['datetime'] ] ]);
			return ['url' => $target, 'response' => $request->process() ];
		break;
		case 'DirectBccMailParser':
			$target = $config['api']['basepath'] . $humhubApiPaths['postMessage'] . '/' . $targetContainer;
			$request = new Request('POST', $target, $config['api_credentials']['mailbot'], [ 'data' => [ 'message' => $data['markdown'], 'datetime' => $data['datetime'] ] ]);
			return ['url' => $target, 'response' => $request->process() ];
		break;
		case 'WhatsAppParser':
			$target = $config['api']['basepath'] . $humhubApiPaths['postMessage'] . '/' . $targetContainer;
			$request = new Request('POST', $target, $config['api_credentials']['imbot'], [ 'data' => [ 'message' => $data['markdown'], 'datetime' => $data['datetime'] ] ]);
			return ['url' => $target, 'response' => $request->process() ];
		break;

	}
});


// Run the parser, close IMAP and output results
$mails = $parser->run();
imap_close($mailbox);

echo json_encode($mails);

$filename = date('Y-m-d');
$time = date('H:i:s');

if ($mails['code'] == 2) {
	// Log output
	//file_put_contents('logs/' . $filename . '.log', $time . ' | ' . json_encode($mails) . PHP_EOL, FILE_APPEND);
}
