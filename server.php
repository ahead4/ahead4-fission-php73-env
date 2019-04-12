<?php

require __DIR__ . '/vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PhpParser\ParserFactory;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Server;
use RingCentral\Psr7\Response;

const V1_CODEPATH = '/userfunc/user';
const V1_USER_FUNCTION = 'handler';
const HANDLER_DIVIDER = '::';

$codePath = null;
$userFunction = null;
$logger = new Logger("Function");
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));


$loop = React\EventLoop\Factory::create();

$server = new Server(function (ServerRequestInterface $request) use (&$codePath, &$userFunction, $logger) {
	$path = $request->getUri()->getPath();
	$method = $request->getMethod();

	$logger->debug($method . ' ' . $path);

	if ('/specialize' === $path && 'POST' === $method) {
		$codePath = V1_CODEPATH;
		$userFunction = V1_USER_FUNCTION;

		return new Response(201);
	}

	if ('/v2/specialize' === $path && 'POST' === $method) {
		$body = json_decode($request->getBody()->getContents(), true);
		$filepath = $body['filepath'];
		list ($moduleName, $userFunction) = explode(HANDLER_DIVIDER, $body['functionName']);
		if (true === is_dir($filepath)) {
			$codePath = $filepath . DIRECTORY_SEPARATOR . $moduleName;

		} else {
			$codePath = $filepath;
		}

		return new Response(201);
	}

	if ('/' === $path) {
		if (null === $codePath) {
			$logger->error("$codePath not found");
			return new Response(500, [], 'Generic container: no requests supported');
		}

		ob_start();

		if (!file_exists($codePath)) {
			$logger->error("$codePath not found");
			return new Response(500, [], "$codePath not found");
		}

		$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
		try {
			$parser->parse(file_get_contents($codePath));
		} catch (Throwable $throwable) {
			$logger->error($codePath . ' - ' . $throwable->getMessage());
			return new Response(500, [], $codePath . ' - ' . $throwable->getMessage());
		}

		require_once $codePath;

		if (function_exists($userFunction)) {
			$logger->debug('Calling user function');

			$response = new Response();
			ob_end_clean();

			$userFunction([
				'request'  => $request,
				'response' => $response,
				'logger'   => $logger,
			]);
			
			return $response;
		} else {
			$logger->debug('User function doesnt exist');
		}

		$bodyRowContent = ob_get_contents();
		ob_end_clean();

		return new Response(200, [], $bodyRowContent);
	}

	return new Response(404, ['Content-Type' => 'text/plain'], 'Not found');
});

$socket = new React\Socket\Server('0.0.0.0:8888', $loop);
$server->listen($socket);

$loop->run();
