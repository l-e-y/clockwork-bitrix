<?php
require_once 'vendor/autoload.php';
$GLOBALS['DB']->ShowSqlStat = true;
// TODO: ?show_cache_stat=Y

use Bitrix\Main\Data\Cache;
use Clockwork\Support\Vanilla\Clockwork;
use Bitrix\Main\Application as BitrixApp;
use Bitrix\Main\DB\Connection as BitrixConnection;
use Bitrix\Main\Diag\SqlTracker as BitrixSqlTracker;
use Bitrix\Main\Loader as BitrixLoader;
use Bitrix\Main\Diag\Debug;

// init before using for clock() helper available
ClockworkAdapter::init();

class LoaderEx extends BitrixLoader {
    public static function getModules()
    {
        return self::$loadedModules;
    }
}

class ClockworkAdapter {
    private static ?Clockwork $profiler;
    private static BitrixConnection $bitrixConnection;
    private static BitrixSqlTracker $bitrixSqlTracker;
    private static CDebugInfo $debug;

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

        static::$debug = new CDebugInfo();
        static::$debug->Start();

        static::$bitrixConnection = BitrixApp::getInstance()->getConnection();
        static::$bitrixSqlTracker = static::$bitrixConnection->startTracker();

        //static::$debug->savedTracker = static::$bitrixConnection->startTracker();
    }

    public static function onEpilog()
    {
        clock('onEpilog');
        clock('Add SQL queries');
        static::addDb();
        clock('Stop Bitrix SQL tracker');
        static::$debug->Stop();
        //Debug::dump(static::$debug->arResult['CACHE']);
        clock('Bitrix Cache tracker');
        static::addCache();
        clock('End request');
        static::getProfiler()->requestProcessed();
    }

    public static function addDb()
    {
        foreach (static::$bitrixSqlTracker->getQueries() as $query) {
            clock()->addDatabaseQuery(
                $query->getSql(),
                $query->getBinds(),
                $query->getTime() * 1000,
                [
                    'trace' => $query->getTrace(),
                    'model' => 'ModelModelModelModel' // hack to show left trace
                ]
            );
        }
    }

    public static function addCache()
    {
        $tracks = static::$debug->arResult['CACHE'];
        foreach ($tracks as $track) {
            $key = $track['initdir'] . ' (' .$track['cache_size'] . 'B)';
            clock()->addCacheQuery($track['path'], $key, $track['cache_size'], 1, $data = [
                'file' => $track['callee_func'],
                'line' => 0,
                'trace' => $track['TRACE'],
                'connection' => $track['callee_func']
            ]);
        }
    }
}

AddEventHandler('main', 'OnEpilog', 'ClockworkAdapter::onEpilog');

// temporarily change original function name at
// bitrix/modules/main/classes/general/module.php line 424
function ExecuteModuleEventEx($arEvent, $arParams = array())
{
    // event start time
    $time = microtime(true);

    // result
    $r = true;

    if (isset($arEvent["TO_MODULE_ID"])
        && $arEvent["TO_MODULE_ID"]<>""
        && $arEvent["TO_MODULE_ID"]<>"main")
        if (!CModule::IncludeModule($arEvent["TO_MODULE_ID"]))
            $r = null;
    elseif (isset($arEvent["TO_PATH"])
        && $arEvent["TO_PATH"]<>""
        && file_exists($_SERVER["DOCUMENT_ROOT"].BX_ROOT.$arEvent["TO_PATH"]))
        $r = include_once($_SERVER["DOCUMENT_ROOT"].BX_ROOT.$arEvent["TO_PATH"]);
    elseif (isset($arEvent["FULL_PATH"])
        && $arEvent["FULL_PATH"]<>""
        && file_exists($arEvent["FULL_PATH"]))
        $r = include_once($arEvent["FULL_PATH"]);

    if (array_key_exists("CALLBACK", $arEvent)) {
        //TODO: �������� �������� �� EventManager::getInstance()->getLastEvent();
        global $BX_MODULE_EVENT_LAST;
        $BX_MODULE_EVENT_LAST = $arEvent;

        if (isset($arEvent["TO_METHOD_ARG"]) && is_array($arEvent["TO_METHOD_ARG"]) && count($arEvent["TO_METHOD_ARG"]))
            $args = array_merge($arEvent["TO_METHOD_ARG"], $arParams);
        else
            $args = $arParams;

        $r = call_user_func_array($arEvent["CALLBACK"], $args);
    }
    elseif ($arEvent["TO_CLASS"] != "" && $arEvent["TO_METHOD"] != "") {
        //TODO: �������� �������� �� EventManager::getInstance()->getLastEvent();
        global $BX_MODULE_EVENT_LAST;
        $BX_MODULE_EVENT_LAST = $arEvent;

        if (is_array($arEvent["TO_METHOD_ARG"]) && count($arEvent["TO_METHOD_ARG"]))
            $args = array_merge($arEvent["TO_METHOD_ARG"], $arParams);
        else
            $args = $arParams;

        //php bug: http://bugs.php.net/bug.php?id=47948
        class_exists($arEvent["TO_CLASS"]);
        $r = call_user_func_array(array($arEvent["TO_CLASS"], $arEvent["TO_METHOD"]), $args);
    }

    // add event to clockwork
    $eventId = $arEvent['FROM_MODULE_ID'] . '.' . $arEvent['MESSAGE_ID'];
    $eventName = round((microtime(true) - $time) * 1000, 2) .'ms.'. $eventId.'.'.$arEvent['TO_NAME'];
    clock()->addEvent($eventName, $arEvent, null, [
        'file' => $arEvent['TO_PATH'],
        'listeners' => $arEvent['TO_NAME'],
        'duration' => (microtime(true) - $time) * 1000,
        'trace' => [[
            'file' => $arEvent['TO_PATH'],
            'line' => 0
        ]],
        'line' => 0
    ]);

    return $r;
}