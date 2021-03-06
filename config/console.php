<?php

$paramsName = 'params.php';
$dbName = 'db.php';

if (YII_ENV_DEV) {
    $paramsName = 'params-local.php';
    $dbName = 'db-local.php';
}

Yii::setAlias('@tests', dirname(__DIR__).'/tests/codeception');

$params = require(__DIR__.'/'.$paramsName);
$db = require(__DIR__.'/'.$dbName);

$config = [
    'id' => 'basic-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\commands',
    'components' => [
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'log' => [
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'db' => $db,
    ],
    'params' => $params,
];

if (YII_ENV_DEV) {
    // configuration adjustments for 'dev' environment
    $config['bootstrap'][] = 'gii';
    $config['modules']['gii'] = [
        'class' => 'yii\gii\Module',
    ];
}

return $config;
