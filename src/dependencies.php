<?php

use Latte\Engine;
use Latte\MacroNode;
use Latte\PhpWriter;

// DIC configuration

$container = $app->getContainer();

// monolog
$container['logger'] = function ($c) {
    $settings = $c->get('settings')['logger'];
    $logger = new Monolog\Logger($settings['name']);
    $logger->pushProcessor(new Monolog\Processor\UidProcessor());
    $logger->pushHandler(new Monolog\Handler\StreamHandler($settings['path'], $settings['level']));
    return $logger;
};

//database
$container['db'] = function ($c) {
    $db = $c['settings']['db'];
    $pdo = new PDO("mysql:host=" . $db['dbhost'] . ";dbname=" . $db['dbname'], $db['dbuser'], $db['dbpass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->query("SET NAMES 'utf8'");
    return $pdo;
};

$container['view'] = function($container) use ($settings) {
    $engine = new Engine();
    $engine->setTempDirectory(__DIR__ . '/../cache');

    $latteView = new LatteView($engine, $settings['settings']['renderer']['template_path']);
    $latteView->addParam('router', $container->router);
    $latteView->addMacro('link', function(MacroNode $node, PhpWriter $writer) use ($container) {
        if(strpos($node->args, ' ') !== false) {
            return $writer->write("echo \$router->pathFor(%node.word, %node.args);");
        } else {
            return $writer->write("echo \$router->pathFor(%node.word);");
        }
    });
    return $latteView;
};