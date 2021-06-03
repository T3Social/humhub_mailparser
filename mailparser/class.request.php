<?php

class Request {
	protected $curl;
	protected $method;
	protected $url;
	protected $data;
	protected $credentials = false;

	public function __construct($method, $url, $credentials = false, $data = []) {
		$this->curl = curl_init();
		$this->method = in_array($method, ['POST', 'PUT']) ? $method : 'GET';
		$this->url = $url;
		$this->data = $data;

		if (is_array($credentials) && array_key_exists('username', $credentials) && is_string($credentials['username'])
		&& array_key_exists('password', $credentials) && is_string($credentials['password'])) {
			$this->credentials = [ 'username' => $credentials['username'], 'password' => $credentials['password'] ];
		} else if (is_string($credentials) && substr_count($credentials, ':') >= 1) {
			$parts = explode(':', $credentials, 2);
			$this->credentials = [ 'username' => $parts[0], 'password' => $parts[1] ];
		}
	}

	public function process() {
		switch ($this->method) {
			case "POST":
				curl_setopt($this->curl, CURLOPT_POST, 1);
				if ($this->data) curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode($this->data));
				break;
			case "PUT":
				curl_setopt($this->curl, CURLOPT_PUT, 1);
				break;
			default:
				if (!$this->data) { break; }
				$this->url = sprintf("%s?%s", $this->url, http_build_query($this->data));
		}

		curl_setopt($this->curl, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);

		if ($this->credentials !== false) {
			curl_setopt($this->curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
			curl_setopt($this->curl, CURLOPT_USERPWD, $this->credentials['username'] . ':' . $this->credentials['password']);
		}

		curl_setopt($this->curl, CURLOPT_URL, $this->url);
		curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);

		$result = curl_exec($this->curl);
		curl_close($this->curl);

		if ($result === false) { return false; }
		return json_decode($result, true);
	}
}
