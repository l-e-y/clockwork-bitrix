<?php
require_once 'vendor/autoload.php';

use Clockwork\Support\Vanilla\Clockwork;
use Bitrix\Main\Application as BitrixApp;
use Bitrix\Main\DB\Connection as BitrixConnection;
use Bitrix\Main\Diag\SqlTracker as BitrixSqlTracker;

// init before using for clock() helper available
ClockworkAdapter::init();

class ClockworkAdapter {
    private static ?Clockwork $profiler;
    private static BitrixConnection $bitrixConnection;
    private static BitrixSqlTracker $bitrixSqlTracker;

    public static function getProfiler(): Clockwork
    {
        if (static::$profiler === null)
            static::init();
        return static::$profiler;
    }

    public static function init(): void
    {
        static::$profiler = Clockwork::init([
            'register_helpers' => true,
            'api' => '/__clockwork/index.php?request='
        ]);

        // start Bitrix SQL tracker
        clock('Start Bitrix SQL tracker');
        static::$bitrixConnection = BitrixApp::getInstance()->getConnection();
        static::$bitrixSqlTracker = static::$bitrixConnection->startTracker();
    }

    public static function onEpilog()
    {
        foreach (static::$bitrixSqlTracker->getQueries() as $query) {
            clock()->addDatabaseQuery(
                $query->getSql(),
                $query->getBinds(),
                $query->getTime() * 1000,
                [
                    'trace' => $query->getTrace()
                ]
            );
        }

        clock('onEpilog');
        clock('Stop Bitrix SQL tracker');
        static::$bitrixConnection->stopTracker();
        clock('End request');
        static::getProfiler()->requestProcessed();
    }
}

AddEventHandler('main', 'OnEpilog', 'ClockworkAdapter::onEpilog');