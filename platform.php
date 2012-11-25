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

if(defined('WP_CONTENT_URL') && defined('ABSPATH'))
{
	/* If we're being included inside WordPress, just perform
	 * minimal amounts of setup.
	 */
	define('EREGANSU_MINIMAL_CORE', true);
	define('INSTANCE_ROOT', ABSPATH);
	define('PUBLIC_ROOT', ABSPATH);
	define('CONFIG_ROOT', INSTANCE_ROOT . 'config/');
	define('PLATFORM_ROOT', INSTANCE_ROOT . 'eregansu/');
	define('PLATFORM_LIB', PLATFORM_ROOT . 'lib/');
	define('PLATFORM_PATH', PLATFORM_ROOT . 'platform/');
	define('MODULES_ROOT', INSTANCE_ROOT . 'app/');
	
	global $MODULE_ROOT;
	
	if(!isset($MODULE_ROOT))
	{
		$MODULE_ROOT = MODULES_ROOT;	
	}
}

/* Define our version of uses() before including the core library - this will
 * take precedence.
 */
if(!function_exists('uses'))
{ 
	function uses()
	{
		static $_map = array('url' => 'uri', 'xmlns' => 'uri');
		static $_lib_modules = array('asn1', 'base32', 'cli', 'curl', 'date', 'db', 'dbschema', 'execute', 'ldap', 'mime', 'observer', 'rdf', 'redland', 'request', 'session', 'uri', 'uuid', 'xmlns', 'csv-import', 'xml', 'rdfxmlstream', 'searchengine');
		
		$_modules = func_get_args();
		foreach($_modules as $_mod)
		{
			$_mod = isset($_map[$_mod]) ? $_map[$_mod] : $_mod;
			if(!strcmp($_mod, 'testsuite') || !strcmp($_mod, 'harness'))
			{
				require_once(PLATFORM_ROOT . 'testsuite/' . $_mod . '.php');
			}
			else if(in_array($_mod, $_lib_modules))
			{
				require_once(PLATFORM_LIB . $_mod . '.php');
			}
			else
			{
				require_once(PLATFORM_PATH . $_mod . '.php');
			}
		}
	}
}

/* Initialise the core library */
require_once(dirname(__FILE__) . '/lib/common.php');

if(defined('EREGANSU_MINIMAL_CORE'))
{
	/* If requested to, we can stop here */
	return true;
}

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
				$argv[1] = $_SERVER['argv'][1] = 'setup';
			}
		}
	}

	/* Load the application-wide and per-instance configurations */
	require_once(CONFIG_ROOT . 'config.php');
	require_once(CONFIG_ROOT . 'appconfig.php');
}

/* Load any plugins which have been named in $PLUGINS */

if(!defined('PLUGINS_ROOT')) define('PLUGINS_ROOT', INSTANCE_ROOT . 'plugins/');
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

/* Load the initial set of modules */
require_once(PLATFORM_LIB . 'request.php');
require_once(PLATFORM_LIB . 'session.php');
require_once(PLATFORM_LIB . 'uri.php');

require_once(PLATFORM_PATH . 'routable.php');
require_once(PLATFORM_PATH . 'page.php');
require_once(PLATFORM_PATH . 'template.php');
require_once(PLATFORM_PATH . 'error.php');

/* Our global event sink: at the moment just used to implement a callback from
 * the request class which fires when the session has been initialised.
 */

/**
 * @internal
 */
 
abstract class PlatformRequestObserver
{
	public static function sessionInitialised($req, $session, $data = null)
	{
		if(isset($session->user))
		{
			if(empty($session->user['ttl']) || $session->user['ttl'] < time())
			{
				uses('auth');
				$session->begin();
				if(!isset($session->user['scheme']))
				{
					unset($session->user);
				}
				else if(($engine = Auth::authEngineForScheme($session->user['scheme'])))
				{
					$engine->refreshUserData($session->user);
				}
				else
				{
					unset($session->user);
				}
				$session->commit();
			}
		}
		else if(isset($session->userScheme) && isset($session->userUUID))
		{
			uses('auth');
			if(($engine = Auth::authEngineForScheme($session->userScheme)))
			{
				if(($data = $engine->retrieveUserData($session->userScheme, $session->userUUID)))
				{
					$session->begin();
					$session->user = $data;
					$session->commit();
				}
			}
		}
		else if($req->sapi != 'http')
		{
			uses('auth');
			$engine = Auth::authEngineForScheme('posix');
			if(($data = $engine->retrieveUserData('posix', posix_geteuid())))
			{
				$session->begin();
				$session->user = $data;
				$session->commit();
			}
		}
	}
}

Observers::observe('sessionInitialised', array('PlatformRequestObserver', 'sessionInitialised'));

/* Create an instance of the request class */
$request = Request::requestForSAPI();

/* Create the initial app instance */
$app = App::initialApp();
