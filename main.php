<?php

require_once 'vendor/autoload.php';


use React\Dns\Protocol\Parser;
use React\Dns\Model\Message;
use React\Promise\FulfilledPromise as Fulfilled;
use React\Promise\RejectedPromise as Rejected;

$listen = '0.0.0.0:53';
$upstream = '8.8.8.8';
$tld = 'dkr';

if (isset($_ENV['LISTEN']))
{
	$listen = $_ENV['LISTEN'];
}

if (isset($_ENV['UPSTREAM']))
{
	$upstream = $_ENV['UPSTREAM'];
}

if (isset($_ENV['TLD']))
{
	$tld = $_ENV['TLD'];
}


$args = getopt("t::u::l::", [
	"tld:",
	"listen:",
	"upstream:"
]);

if (isset($args['t']))
{
	$tld = $args['t'];
}

if (isset($args['u']))
{
	$upstream = $args['u'];
}

if (isset($args['l']))
{
	$listen = $args['listen'];
}

if (isset($args['tld']))
{
	$tld = $args['tld'];
}

if (isset($args['upstream']))
{
	$upstream = $args['upstream'];
}

if (isset($args['listen']))
{
	$listen = $args['listen'];
}


print "TLD = {$tld} LISTEN = {$listen} UPSTREAM = {$upstream}\n";


$loop		= React\EventLoop\Factory::create();
$udpfactory	= new React\Datagram\Factory($loop);


class BinaryDumper extends React\Dns\Protocol\BinaryDumper
{
	public function toBinary(Message $message)
	{
		return parent::toBinary($message) . 
			$this->answerToBinary($message->answers);
	}
	
	private function answerToBinary(array $answers)
	{
		$data = "";
		print "ANSWERS:\n";
		print_r($answers);
		
		foreach ($answers as $answer)
		{
			$labels = explode('.', $answer['name']);
			foreach ($labels as $label) {
				$data .= chr(strlen($label)).$label;
			}
			$data .= "\x00";
			
			$data .= pack('n', $answer['type']);
			$data .= pack('n', $answer['class']);
			$data .= pack('N', $answer['ttl']);
			$data .= pack('n', 4);
			$data .= pack('N', ip2long($answer['data']));
		}
		
		return $data;
	}
}

class UnixRequest extends React\HttpClient\Request
{
	protected $_connector;
	protected $socket;
	
	public function __construct(React\SocketClient\ConnectorInterface $connector, React\HttpClient\RequestData $requestData, $socket)
	{
		$this->setSocket($socket);
		$this->_connector = $connector;
		parent::__construct($connector, $requestData);
	}
	
	protected function setSocket($socket)
	{
		$st = stat($socket);
		if ($st === FALSE)
		{
			throw new Exception(posix_strerror(posix_get_last_error()));
		}
		
		if ((stat('/var/run/docker.sock')['mode'] & 0140000) !== 0140000)
		{
			throw new Exception('file is not a unix socket');
		}
		
		$this->socket = $socket;
	}
	
	protected function connect()
	{
		return $this->_connector
			->create($this->socket);
	}
}

class UnixHttpClient extends React\HttpClient\Client
{
	private $_connector;
	private $_socket;
	
	
	public function __construct($connector, $socket)
	{
		$this->_connector = $connector;
		$this->_socket = $socket;
		parent::__construct($connector, $connector);
	}
	
	public function request($method, $url, array $headers = [], $protocolVersion = '1.0')
	{
		$requestData = new React\HttpClient\RequestData($method, $url, $headers, $protocolVersion);
		return new UnixRequest($this->_connector, $requestData, $this->_socket);
	}
}

$connector = new React\SocketClient\UnixConnector($loop);
$client = new UnixHttpClient($connector, '/var/run/docker.sock');


global $dockers;
$dockers = [];


$checkdocker = function() use ($client, $tld)
{
	$request= $client->request(
		'GET',
		'http://localhost/containers/json'
	);
	
	
	$request->on('response', function($response) use ($tld) {
		
		$containers = "";
		
		
		$response->on('data', function($data, $response) use (&$containers) {
			$containers .= $data;
		});
		
		$response->on('end', function() use (&$containers, $tld)  {
			
			global $dockers;
			$hosts = [];
			
			$containers = json_decode($containers);
			
			foreach ($containers as $container)
			{
				$name	= substr($container->Names[0], 1) . '.' . $tld;
				$ip	= $container->NetworkSettings->Networks->bridge->IPAddress;
				
				$hosts[$name] = $ip;
			}
			
			$dockers = $hosts;
		});
		
	});
	
	$request->end();
};


$loop->nextTick($checkdocker);
$loop->addPeriodicTimer(30, $checkdocker);

$dns = (new React\Dns\Resolver\Factory())
	->createCached($upstream, $loop, new React\Cache\ArrayCache());

$udpfactory->createServer($listen)->then(function(React\Datagram\Socket $server) use ($dns) {
	
	$server->on('message', function($message, $address, $server) use ($dns) {
		
		global $dockers;
		
		
		$dumper		= new BinaryDumper;
		$request	= new Message();
		$parser 	= new Parser();
		
		
		$parser->parseChunk($message, $request);
		
		
		$response = new Message();
		
		$header = [
			// copy the id to match the request
			'id'		=> $request->header->get('id'),
			// we are response!
			'qr'		=> 1,
			'opcode'	=> $request->header->get('opcode'),
			// respect my author-i-tie!
			'aa'		=> 1,
			'tc'		=> 0,
			'rd'		=> $request->header->get('rd'),
			// set recursion available to trick dnsmasq
			'ra'		=> 1,
			// no error on response code
			'rcode'		=> Message::RCODE_OK
		];
		
		foreach ($header as $key => $val)
		{
			$response->header->set($key, $val);
		}
		
		$response->questions = $request->questions;
		
		foreach ($request->questions as $question)
		{
			if ($question['type'] == Message::TYPE_A)
			{
				if (isset($dockers[$question['name']]))
				{
					$resolved[] = new Fulfilled([
						'name'		=> $question['name'],
						'type'		=> Message::TYPE_A,
						'class'		=> Message::CLASS_IN,
						// cache for 30 seconds
						'ttl'		=> 30,
						'data'		=> $dockers[$question['name']]
					]);
				}
				else
				{
					print "UPSTREAM: {$question['name']} ...\n";
					
					$resolved[] = $dns->resolve($question['name'])
						->then(function($ip) use ($question, $response) {
							print "RESOLVED: {$ip}\n";
							return new Fulfilled([
								'name'		=> $question['name'],
								'type'		=> Message::TYPE_A,
								'class'		=> Message::CLASS_IN,
								// cache for 30 seconds
								'ttl'		=> 1800,
								'data'		=> $ip
							]);
						});
				}
			}
		}
		
		\React\Promise\all($resolved)
			->then(function($answers) use ($response, $server, $dumper, $address) {
				print "ANSWERS:\n";
				print_r($answers);
				
				$response->answers = $answers;
				$response->prepare();
				$server->send($dumper->toBinary($response), $address);
			})
			->otherwise(function() use ($response, $server, $dumper, $address) {
				$response->header->set('rcode', Message::RCODE_NAME_ERROR);
				$response->prepare();
				$server->send($dumper->toBinary($response), $address);
			});
	});
	
});

ini_set('memory_limit', '2M');
gc_enable();

$loop->addPeriodicTimer(360, function() {
	gc_collect_cycles();
});

while (1) $loop->run();
