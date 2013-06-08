<?php

/* Eregansu - A lightweight web application platform
 *
 * Copyright 2009-2012 Mo McRoberts.
 *
 *  Licensed under the Apache License, Version 2.0 (the "License");
 *  you may not use this file except in compliance with the License.
 *  You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 *  Unless required by applicable law or agreed to in writing, software
 *  distributed under the License is distributed on an "AS IS" BASIS,
 *  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *  See the License for the specific language governing permissions and
 *  limitations under the License.
 */

/* This script is usually included by an instance's index.php and is
 * responsible for dragging in all of the pieces of Eregansu and getting
 * the environment ready for routing a request. By the time the end of
 * the script is reached, all an application typically has to do is
 * call:
 *
 * $app->process($request);
 *
 * ...but it is, of course, free to do whatever it wants.
 *
 */

/**
 * @framework Eregansu
 */

/* Initialise the core library */
require_once(dirname(__FILE__) . '/../lib/common.php');

if(!defined('EREGANSU_FRAMEWORK'))
{
	define('EREGANSU_FRAMEWORK', PLATFORM_ROOT . 'framework/');
}

if(defined('EREGANSU_MINIMAL_CORE'))
{
	/* If requested to, we can stop here */
	return true;
}

/* Register modules with uses() */
$EREGANSU_MODULES['auth'] = EREGANSU_FRAMEWORK . 'auth.php';
$EREGANSU_MODULES['cli'] = EREGANSU_FRAMEWORK . 'cli.php';
$EREGANSU_MODULES['error'] = EREGANSU_FRAMEWORK . 'error.php';
$EREGANSU_MODULES['form'] = EREGANSU_FRAMEWORK . 'form.php';
$EREGANSU_MODULES['id'] = EREGANSU_FRAMEWORK . 'id.php';
$EREGANSU_MODULES['model'] = EREGANSU_FRAMEWORK . 'model.php';
$EREGANSU_MODULES['page'] = EREGANSU_FRAMEWORK . 'page.php';
$EREGANSU_MODULES['rdfstore'] = EREGANSU_FRAMEWORK . 'rdfstore.php';
$EREGANSU_MODULES['routable'] = EREGANSU_FRAMEWORK . 'routable.php';
$EREGANSU_MODULES['store'] = EREGANSU_FRAMEWORK . 'store.php';
$EREGANSU_MODULES['template'] = EREGANSU_FRAMEWORK . 'template.php';

/* Register classes with the class autoloader */
$AUTOLOAD_SUBST['${framework}'] = EREGANSU_FRAMEWORK;
$AUTOLOAD['error'] = dirname(__FILE__) . '/error.php';
$AUTOLOAD['form'] = dirname(__FILE__) . '/form.php';
$AUTOLOAD['model'] = dirname(__FILE__) . '/model.php';
$AUTOLOAD['page'] = dirname(__FILE__) . '/page.php';
$AUTOLOAD['loader'] = dirname(__FILE__) . '/routable.php';
$AUTOLOAD['routable'] = dirname(__FILE__) . '/routable.php';
$AUTOLOAD['redirect'] = dirname(__FILE__) . '/routable.php';
$AUTOLOAD['router'] = dirname(__FILE__) . '/routable.php';
$AUTOLOAD['app'] = dirname(__FILE__) . '/routable.php';
$AUTOLOAD['defaultapp'] = dirname(__FILE__) . '/routable.php';
$AUTOLOAD['hostnamerouter'] = dirname(__FILE__) . '/routable.php';
$AUTOLOAD['proxy'] = dirname(__FILE__) . '/routable.php';
$AUTOLOAD['commandline'] = dirname(__FILE__) . '/routable.php';
$AUTOLOAD['template'] = dirname(__FILE__) . '/template.php';

if(!defined('EREGANSU_SKIP_CONFIG'))
{  
	if(isset($argv[1]) && ($argv[1] == 'setup' || $argv[1] == 'install'))
	{
		if(php_sapi_name() == 'cli')
		{
			if($argv[1] == 'install' || !file_exists(CONFIG_ROOT) || !file_exists(CONFIG_ROOT . 'config.php') || !file_exists(CONFIG_ROOT . 'appconfig.php'))
			{
				require_once(PLATFORM_ROOT . 'install/installer.php');
			}
			if($argv[1] == 'install')
			{
				/* After running the installer, perform a schema update */
				$argv[1] = $_SERVER['argv'][1] = 'migrate';
			}
		}
	}

	/* Load the application-wide and per-instance configurations */
	require_once(CONFIG_ROOT . 'config.php');
	require_once(CONFIG_ROOT . 'appconfig.php');
}

/* Load any plugins which have been named in $PLUGINS */

if(!isset($PLUGINS) || !is_array($PLUGINS)) $PLUGINS = array();

/* Ensure plugins can register observers */
require_once(PLATFORM_LIB . 'observer.php');

foreach($PLUGINS as $pluginName)
{
	$pluginBase = basename($pluginName);
	$pluginPath = $pluginName[0] == '/' ? $pluginName : PLUGINS_ROOT . $pluginName;
	if(file_exists($pluginPath . '/' . $pluginBase . '.php'))
	{
		require_once($pluginPath . '/' . $pluginBase . '.php');
	}
	else if(file_exists($pluginpath . '.php'))
	{
		require_once($pluginPath . '.php');
	}
	else
	{
		trigger_error('Cannot locate plugin "' . $pluginName . '"', E_USER_NOTICE);
	}
}

/* Create an instance of the request class */
$request = Request::requestForSAPI();

/* Create the initial app instance */
$app = App::initialApp($request->sapi);
