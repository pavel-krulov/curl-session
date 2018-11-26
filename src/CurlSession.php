<?php

namespace Pk13\CurlSession;

/**
 * Class CurlSession
 *
 * Class makes several http requests and save cookies between them.
 */
class CurlSession
{
	const HTTP_EOL = "\r\n";

	private $opts = [];
	private $opts_session = [];
	private $opts_default = [];
	private $headers = [];
	/* @var resource|null */
	private $curl;
	private $lastResponseHeaders = [];
	private $headersHasFinished = false;
	private $logfile;
	private $lastSendPostBody;
	private $delay=null;

	public function __construct($enableCookies=false)
	{
		$this->opts_default = [
			CURLOPT_CONNECTTIMEOUT => 2,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_MAXREDIRS => 10,
		];

		if($enableCookies) {
			$cookieFile = tempnam(sys_get_temp_dir(), 'pk13_curl_session_');
			$this->opts_default[CURLOPT_COOKIEJAR] = $cookieFile;
			$this->opts_default[CURLOPT_COOKIEFILE] = $cookieFile;
		}
	}

	/**
	 * Similar as curl_setopt.
	 * This options clean out after every http request
	 * For permanent options use setopt_session
	 *
	 * @param $name
	 * @param $value
	 */
	public function setopt($name, $value)
	{
		$this->opts[$name] = $value;
	}

	/**
	 * Similar as curl_setopt.
	 * This options are stored during the session
	 *
	 * @param $name
	 * @param $value
	 */
	public function setopt_session($name, $value)
	{
		$this->opts_session[$name] = $value;
	}

	/**
	 * Enable writing verbode information to SDTERR
	 */
	public function verbose_enable()
	{
		$this->setopt_session(CURLOPT_VERBOSE, true);
	}

	/**
	 * Setup header for next request
	 *
	 * @param string $header
	 */
	public function set_header($header)
	{
		$this->headers[] = $header;
	}

	/**
	 * Enable logs writing
	 *
	 * @param string $path path to log file
	 */
	public function set_log_file($path)
	{
		$this->logfile = $path;
	}

	/**
	 * Set delay between requests
	 *
	 * @param float $sec  delay
	 */
	public function set_delay($sec)
	{
		$this->delay = [
			'delay' => $sec,
			'last'	=> 0, //time of last request
		];
	}

	/**
	 * Make http POST request
	 * @param string $url
	 * @param array $data - key value array of fields
	 * @return string|false response body or false on error
	 */
	public function POST($url, $data)
	{
		$body =  http_build_query($data);
		$this->setopt(CURLOPT_POST, true);
		$this->setopt(CURLOPT_POSTFIELDS, $body);
		$this->lastSendPostBody = $body;

		return $this->request($url);
	}

	/**
	 * Make http GET request
	 * @param string $url
	 * @return string|false response body or false on error
	 */
	public function GET($url)
	{
		return $this->request($url);
	}

	/**
	 * Return header from last response or null if it isn't exist
	 *
	 * @param string $name
	 * @return string|null
	 */
	public function getResponseHeader($name)
	{
		$name = strtolower($name);

		return isset($this->lastResponseHeaders[$name]) ? $this->lastResponseHeaders[$name] : null;
	}

	/**
	 * Return all headers from last response
	 *
	 * @return array
	 */
	public function getResponseHeaders()
	{
		return $this->lastResponseHeaders;
	}

	/**
	 * Similar as curl_getinfo.
	 *
	 * @param int $const - predefined CURL_* constant @see http://php.net/manual/en/function.curl-getinfo.php
	 * @return string|array
	 */
	public function getinfo($const=null)
	{
		if (null !== $this->curl) {
			return curl_getinfo($this->curl, $const);
		}
	}


	/**
	 * Similar as curl_error.
	 *
	 * @return string|array
	 */
	public function geterror()
	{
		if (null !== $this->curl) {
			return curl_error($this->curl);
		}
	}


	public function __destruct()
	{
		if (null !== $this->curl) {
			curl_close($this->curl);
		}
	}

	/**
	 * Make request
	 *
	 * @param string $url
	 * @return string|false response body or false on error
	 */
	private function request($url)
	{
		$this->realeaseDelay();
		$this->curl = curl_init($url);
		$fixedOpts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADERFUNCTION => function($curl, $header) { return $this->storeHeader($header); },
			CURLOPT_HTTPHEADER => $this->headers
		];
		//array from left is more important
		$opts = $fixedOpts + $this->opts + $this->opts_session + $this->opts_default;
		curl_setopt_array($this->curl, $opts);

		$this->opts = [];
		$response = curl_exec($this->curl);
		$this->netlog($response);

		$this->lastSendPostBody=null;
		$this->headers = [];

		return $response;
	}

	/**
	 * Store inside headers into private property
	 *
	 * @param string $header
	 * @return int length of header
	 */
	private function storeHeader($header)
	{
		if(true === $this->headersHasFinished) {
			//drop headers from last request
			$this->lastResponseHeaders=[];
			$this->headersHasFinished = false;
		}

		if($header === self::HTTP_EOL){
			//headers have finished
			$this->headersHasFinished=true;
		}
		elseif(preg_match('/^([a-z0-9\-_]+): (.+)/i', $header, $m)) {
			$this->lastResponseHeaders[strtolower($m[1])] = trim($m[2]);
		}
		else {
			$this->lastResponseHeaders[] = trim($header);
		}
		return strlen($header);
	}

	/**
	 * Write log for network operation
	 *
	 * @param string $response
	 */
	private function netlog($response)
	{
		if(null !== $this->logfile) {
			$lastError = curl_error($this->curl);
			$redirectUrl = $this->getinfo(CURLINFO_REDIRECT_URL);
			$now = time();
			$data = [
				'date'=>date('c', $now),
				'unixtime'=>$now,
				'url' => $this->getinfo(CURLINFO_EFFECTIVE_URL),
				'http_code' => $this->getinfo(CURLINFO_HTTP_CODE),
				'last_error'=>(!empty($lastError) ? $lastError : '-'),
				'redirect_cnt'=>$this->getinfo(CURLINFO_REDIRECT_COUNT),
				'redirect_url'=>(!empty($redirectUrl) ? $redirectUrl : '-'),
				'total_time'=>$this->getinfo(CURLINFO_TOTAL_TIME),
				'req_headers'=>$this->headers,
				'req_body'=>(null===$this->lastSendPostBody ? '-' : $this->lastSendPostBody),
				'res_headers'=>$this->getResponseHeaders(),
				'res_length'=>strlen($response),
				'res_body'=>$response
			];
			$json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR);
			error_log($json.PHP_EOL, 3, $this->logfile);
		}
	}


	private function realeaseDelay()
	{
		if(null!==$this->delay) {
			$now = microtime(true);
			$shift = $now-$this->delay['last'];
			if($this->delay['delay'] > $shift) {
				$microsec = ($this->delay['delay']-$shift) * 1000000;
				usleep( $microsec );
			}
			$this->delay['last'] = microtime(true);
		}
	}

}