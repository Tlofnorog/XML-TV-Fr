<?php

/* Thread to gather one channel */
require_once 'vendor/autoload.php';
error_reporting(0);
use racacax\XmlTv\Component\ProcessCache;
use racacax\XmlTv\Component\Utils;
use racacax\XmlTv\Component\XmlFormatter;
use racacax\XmlTv\Configurator;

$client = Configurator::getDefaultClient();
$providers = Utils::getProviders();
$providerClass = null;
$data = json_decode(base64_decode($argv[3]), true);
foreach ($providers as $provider) {
    $e = explode('\\', $provider);
    if (end($e) == $argv[1]) {
        $providerClass = $provider;

        break;
    }
}
define('CHANNEL_PROCESS', $argv[4]);
$provider = new $providerClass($client, null, $data['extraParams']);

try {
    date_default_timezone_set('Europe/Paris');
    $obj = $provider->constructEpg($data['key'], $argv[2]);
} catch (Throwable $e) {
    $obj = false;
}
if ($obj === false || $obj->getProgramCount() === 0) {
    $data = 'false';
} else {
    $formatter = new XmlFormatter();
    $data = $formatter->formatChannel($obj, $provider);
}
// Lock file present to avoid main thread to read file while thread is still writing content into it
(new ProcessCache('cache'))->save($argv[4].'.lock', '');
(new ProcessCache('cache'))->save($argv[4], $data);
(new ProcessCache('cache'))->pop($argv[4].'.lock');
