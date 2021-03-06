<?php

/* Eregansu: Classes which can process requests
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

/**
 * @year 2009-2012
 * @include uses('routable');
 * @since Available in Eregansu 1.0 and later.
 * @task Processing requests
 */

uses('request', 'session', 'uri', 'error');

/**
 * The interface implemented by all classes which can process requests.
 */

interface IRequestProcessor
{
	public function process(Request $req);
}

/**
 * Route module loader.
 */
abstract class Loader
{
	/**
	 * Attempt to load the module which handles a route.
	 *
	 * @type boolean
	 * @param[in] array $route An associative array containing route information.
	 * @return \c{true} if the module was loaded successfully, \c{false} otherwise.
	 */
	public static function load($route)
	{
		global $MODULE_ROOT;

		if(!is_array($route))
		{
			return $false;
		}
		if(!empty($route['adjustBase']) || !empty($route['adjustModuleBase']))
		{
			if(isset($route['name']))
			{
				$MODULE_ROOT .= $route['name'] . '/';
			}
			else if(substr($route['key'], 0, 1) != '_')
			{
				$MODULE_ROOT .= $route['key'] . '/';
			}
		}
		$f = null;
		if(isset($route['file']))
		{
			$f = $route['file'];
			if(substr($f, 0, 1) != '/' && isset($route['name']) && empty($route['adjustBase']) && empty($route['adjustModuleBase']))
			{
				$f = $route['name'] . '/' . $f;
			}
			if(substr($f, 0, 1) != '/')
			{
				if(!empty($route['fromRoot']))
				{
					$f = MODULES_ROOT . $f;
				}
				else
				{
					$f = $MODULE_ROOT . $f;
				}
			}
			require_once($f);
		}
		return true;
	}
}

/**
 * Base class for all Eregansu-provided routable instances.
 *
 * The \class{Routable} class is the ultimate ancestor of all classes which
 * process \class{Request} instances and perform actions based upon their
 * properties (typically producing some kind of output). The \class{Routable}
 * class implements the [[IRequestProcessor]] interface.
 */
class Routable implements IRequestProcessor
{
	protected $model;
	protected $modelClass = null;
	protected $modelArgs = null;
	protected $crumbName = null;
	protected $crumbClass = null;
	
	/**
	 * Initialise a \class{Routable} instance.
	 *
	 * Constructs an instance of [[Routable]]. If the protected property [[Routable::$modelClass]] has been set, then the class named by that property’s `[[getInstance|Model::getInstance]]()` method will be invoked and its return value will be set as the protected property [[Routable::$model]]. If [[Routable::$modelArgs]] is set, it will be passed as the first parameter in the call to `[[getInstance|Model::getInstance]]()`.
	 */
	public function __construct()
	{
		if(strlen($this->modelClass))
		{
			$this->model = call_user_func(array($this->modelClass, 'getInstance'), $this->modelArgs);
		}
	}
	
	public function process(Request $req)
	{
		if(isset($req->data['crumbName'])) $this->crumbName = $req->data['crumbName'];
		$this->addCrumb($req);
	}
	
	protected function addCrumb(Request $req = null)
	{
		if($req === null)
		{
			return;
		}
		if($this->crumbName !== null)
		{
			$req->addCrumb(array('name' => $this->crumbName, 'class' => $this->crumbClass));
		}
	}
	
	protected function error($code, Request $req = null, $object = null, $detail = null)
	{
		throw new Error($code, $object, $detail);
	}
}

/**
 * Perform a redirect when a route is requested.
 */
class Redirect extends Routable
{
	protected $target = '';
	protected $useBase = true;
	protected $fromPage = false;

	public function process(Request $req)
	{
		$targ = $this->target;
		$useBase = $this->useBase;
		$fromPage = $this->fromPage;
		if(isset($req->data['target']))
		{
			$targ = $req->data['target'];
		}
		if(isset($req->data['useBase']))
		{
			$useBase = $req->data['useBase'];
		}
		if(isset($req->data['fromPage']))
		{
			$fromPage = $req->data['fromPage'];
		}
		if(substr($targ, 0, 1) == '/')
		{
			$targ = substr($targ, 1);
			if($this->useBase)
			{
				$req->redirect($req->base . $targ);
			}
			$req->redirect($req->root . $targ);
		}
		else if($fromPage || strpos($targ, ':') === false)
		{
			$req->redirect($req->pageUri . $targ);
		}
		else if(strlen($targ))
		{			
			$req->redirect($targ);
		}
	}
}

/**
 * A routable class capable of passing a request to child routes.
 */
class Router extends Routable
{
	protected $sapi = array('http' => array(), 'cli' => array());
	protected $routes;

	/**
	 * @internal
	 */
	public function __construct()
	{
		/* Shorthand, as most stuff is web-based */
		$this->routes =& $this->sapi['http'];
		parent::__construct();
	}
	
	protected function getRouteName(Request $req, &$consume)
	{
		if(isset($req->params[0]))
		{
			$consume = true;
			return $req->params[0];
		}
		return null;
	}

	/**
	 * @internal
	 */	
	public function locateRoute(Request $req)
	{
		global $MODULE_ROOT;
		
		if(!isset($this->sapi[$req->sapi])) return null;
		$routes = $this->sapi[$req->sapi];
		if(isset($req->data['routes']))
		{
			$routes = $req->data['routes'];
		}
		$consume = false;
		$k = $this->getRouteName($req, $consume);
		if(!strlen($k))
		{
			$k = '__NONE__';
		}
		if(!isset($routes[$k]) && isset($routes['__DEFAULT__']))
		{
			$k = '__DEFAULT__';
			$consume = false;
		}
		if(isset($routes[$k]))
		{
			$route = $routes[$k];
			if(is_array($req->data))
			{
				$data = $req->data;
				unset($data['name']);
				unset($data['file']);
				unset($data['adjustBase']);
				unset($data['adjustBaseURI']);
				unset($data['adjustModuleBase']);
				unset($data['routes']);
				unset($data['crumbName']);
				unset($data['class']);
				unset($data['_routes']);
				$data = array_merge($data, $route);
			}
			else
			{
				$data = $route;
			}
			$data['key'] = $k;
			$data['_routes'] = $routes;
			$req->data = $data;
			if($consume)
			{
				if(!empty($data['adjustBase']) || !empty($data['adjustBaseURI']))
				{
					$req->consumeForApp();
				}
				else
				{
					$req->consume();
				}
			}
			return $data;
		}
		return null;
	}
	
	public function process(Request $req)
	{
		parent::process($req);
		$route = $this->locateRoute($req);
		if($route)
		{
			if(isset($route['require']))
			{
				if(!($this->authoriseRoute($req, $route))) return false;
			}
			if(!($target = $this->routeInstance($req, $route))) return false;
			return $target->process($req);
		}
		return $this->unmatched($req);
	}
	
	protected function authoriseRoute(Request $req, $route)
	{
		$perms = $route['require'];
		if(!is_array($perms))
		{
			$perms = ($perms ? array($perms) : array());
		}
		if(in_array('*/*', $req->types) || in_array('text/html', $req->types))
		{
			$match = true;
			foreach($perms as $perm)
			{
				if(!isset($req->session->user) || !isset($req->session->user['perms']) || !in_array($perm, $req->session->user['perms']))
				{
					$match = false;
				}
			}
			if(!$match)
			{
				if($req->session->user)
				{
					$p = new Error(Error::FORBIDDEN);
					return $p->process($req);
				}
				else
				{
					$iri = (defined('LOGIN_IRI') ? LOGIN_IRI : $req->root . 'login');
					return $req->redirect($iri . '?redirect=' . str_replace(';', '%3b', urlencode($req->uri)));
				}
			}
		}
		else
		{
			$success = false;
			if(isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW']))
			{
				uses('auth');
				$iri = $_SERVER['PHP_AUTH_USER'];
				$scheme = null;
				if(($engine = Auth::authEngineForToken($iri, $scheme)))
				{
					if(($user = $engine->verifyToken($req, $scheme, $iri, $_SERVER['PHP_AUTH_PW'])))
					{
						$req->beginTransientSession();
						$req->session->user = $user;
						$success = true;
					}
				}
			}
			if(!$success)
			{
				$req->header('WWW-Authenticate', 'basic realm="' . $req->hostname . '"');
				$p = new Error(Error::AUTHORIZATION_REQUIRED);
				return $p->process($req);
			}
		}
		return true;
	}

	/**
	 * @internal
	 */	
	public function routeInstance(Request $req, $route)
	{
		global $MODULE_ROOT;

		if(!Loader::load($route))
		{
			return $this->error(Error::NOT_IMPLEMENTED, $req, null, 'The route data for this key is not an array');
		}
		if(!isset($route['class']) || !class_exists($route['class']))
		{
			return $this->error(Error::NOT_IMPLEMENTED, $req, null, 'Class ' . @$route['class'] . ' is not implemented in ' . (isset($f) ? $f : " current context"));
		}
		$target = new $route['class']();
		if(!$target instanceof IRequestProcessor)
		{
			return $this->error(Error::ROUTE_NOT_PROCESSOR, $req, null, 'Class ' . $route['class'] . ' is not an instance of IRequestProcessor');
		}
		return $target;		
	}
	
	protected function unmatched(Request $req)
	{
		return $this->error(Error::ROUTE_NOT_MATCHED, $req, null, 'No suitable route could be located for the specified path');
	}
}

/**
 * A routable class which encapsulates an application.
 */
class App extends Router
{
	public $parent;
	public $skin;
	public $theme;

	protected static $initialApp = array();

	public static function initialApp($sapi = null)
	{
		global $MODULE_ROOT;

		if(!strlen($sapi))
		{
			$sapi = php_sapi_name();
		}
		if(isset(self::$initialApp[$sapi]))
		{
			return self::$initialApp[$sapi];
		}
		$prefix = str_replace('-', '_', strtoupper($sapi));
		if(defined($prefix . '_MODULE_CLASS'))
		{
			if(defined($prefix . '_MODULE_NAME'))
			{
				$MODULE_ROOT .= constant($prefix . '_MODULE_NAME') . '/';
			}
			if(defined($prefix . '_MODULE_CLASS_PATH'))
			{
				require_once($MODULE_ROOT . constant($prefix . '_MODULE_CLASS_PATH'));
			}
			$appClass = constant($prefix . '_MODULE_CLASS');
			self::$initialApp[$sapi] = $inst = new $appClass;
			return $inst;
		}
		if(isset(self::$initialApp['default']))
		{
			return self::$initialApp['default'];
		}
		if(defined('MODULE_NAME'))
		{
			$MODULE_ROOT .= MODULE_NAME . '/';
		}
		if(defined('MODULE_CLASS'))
		{
			if(defined('MODULE_CLASS_PATH'))
			{
				require_once($MODULE_ROOT . MODULE_CLASS_PATH);
			}
			$appClass = MODULE_CLASS;
			self::$initialApp['default'] = $inst = new $appClass;
			return $inst;
		}
		self::$initialApp['default'] = $inst = new DefaultApp;
		return $inst;
	}

	public function __construct()
	{
		parent::__construct();
		if(!isset($this->routes['login']))
		{
			$this->routes['login'] = array('file' => PLATFORM_ROOT . 'login/app.php', 'class' => 'LoginPage', 'fromRoot' => true);
		}
		$help = array('file' => EREGANSU_FRAMEWORK . 'cli.php', 'class' => 'CliHelp', 'fromRoot' => true);
		if(!isset($this->sapi['cli']['__DEFAULT__']))
		{
			$this->sapi['cli']['__DEFAULT__'] = $help;
		}
		if(!isset($this->sapi['cli']['__NONE__']))
		{
			$this->sapi['cli']['__NONE__'] = $help;
		}
		if(!isset($this->sapi['cli']['help']))
		{
			$this->sapi['cli']['help'] = $help;
		}
	}
	
	public function process(Request $req)
	{
		$this->parent = $req->app;
		$req->app = $this;
		try
		{
			$r = parent::process($req);
			while(is_object($r) && $r instanceof IRequestProcessor)
			{
				$r = $r->process($req);
			}
		}
		catch(Error $e)
		{
			$e->process($req);
			throw $e;
		}
		$req->app = $this->parent;
		$this->parent = null;
	}
}

/**
 * The default application class.
 */
class DefaultApp extends App
{
	public function __construct()
	{
		global $HTTP_ROUTES, $CLI_ROUTES, $MQ_ROUTES;
		
		$this->sapi['http'] = $HTTP_ROUTES;
		$this->sapi['cli'] = $CLI_ROUTES;
		$this->sapi['mq'] = $MQ_ROUTES;
		parent::__construct();
		$help = array('file' => EREGANSU_FRAMEWORK . 'cli.php', 'class' => 'CliHelp', 'fromRoot' => true);
		if(!isset($this->sapi['cli']['__DEFAULT__']))
		{
			$this->sapi['cli']['__DEFAULT__'] = $help;
		}
		if(!isset($this->sapi['cli']['__NONE__']))
		{
			$this->sapi['cli']['__NONE__'] = $help;
		}
		if(!isset($this->sapi['cli']['help']))
		{
			$this->sapi['cli']['help'] = $help;
		}
	}
}

/**
 * Route requests to a particular app based upon a domain name.
 */
class HostnameRouter extends DefaultApp
{
	public function __construct()
	{
		global $HOSTNAME_ROUTES;
		
		parent::__construct();
		
		$this->sapi['http'] = $HOSTNAME_ROUTES;
	}
	
	protected function getRouteName(Request $req, &$consume)
	{
		if($req->sapi == 'http')
		{
			return $req->hostname;
		}
		return parent::getRouteName($req, $consume);
	}
}

/**
 * Routable class designed to support presenting views of data objects.
 *
 * The \class{Proxy} class is a descendant of \class{Router} intended to be
 * used in situations where objects are retrieved via a \class{Model} and
 * presented according to the \class{Request}. That is, conceptually,
 * descendants of this class are responsible for proxying objects from storage
 * to presentation. \class{Page} and \class{CommandLine} are notable
 * descendants of \class{Proxy}.
 */
class Proxy extends Router
{
	public static $willPerformMethod;

	public $request;
	public $proxyUri;
	protected $supportedTypes = array();
	protected $supportedMethods = array('OPTIONS', 'GET', 'HEAD');
	protected $supportedLangs = null;
	protected $noFallThroughMethods = array('OPTIONS', 'GET', 'HEAD', '__CLI__', '__MQ__');
	protected $negotiateMethods = array('HEAD', 'GET', 'POST', 'PUT');
	protected $object = null;
	protected $sessionObject = null;
	protected $sendNegotiateHeaders = true;
	protected $swallowIndex = true;
	protected $negotiatedType = null;
	protected $autoSupportedTypes = false;
	protected $negotiatedLang = null;
	
	protected function unmatched(Request $req)
	{
		$this->request = $req;
		$this->proxyUri = $this->request->pageUri;
		$method = $req->method;
		/* If the last element in $req->params[] is the index resource name (usually
		 * 'index'), silently ignore it unless $this->swallowIndex is false.
		 */
		if($this->swallowIndex && ($n = count($req->params)) && !strcmp($req->params[$n - 1], INDEX_RESOURCE_NAME))
		{
			array_pop($req->params);
		}
		if(($r = $this->getObject()) !== true)
		{
			$this->request = null;
			$this->sessionObject = null;
			return $r;
		}
		/* We always perform content negotiation; however, for those methods
		 * which do not appear in $this->negotiateMethods, a failure to
		 * negotiate a type is ignored.
		 */
		if($this->autoSupportedTypes)
		{
			if(is_object($this->object) && method_exists($this->object, 'serialisations'))
			{
				$this->addSupportedTypes($this->object->serialisations());
			}
			else if(is_object($this->objects) && method_exists($this->objects, 'serialisations'))
			{
				$this->addSupportedTypes($this->objects->serialisations());
			}								
		}
        $r = $this->negotiate($req);
        $type = isset($this->negotiatedType) ? $this->negotiatedType['type'] : 'text/html';
		if(is_array($r))
		{
			if($this->sendNegotiateHeaders)
			{
				foreach($r as $k => $value)
				{
					if($k == 'Status') continue;
                    if($k == 'Vary')                        
                    {
                        $req->vary($value);
                        continue;
                    }
					$req->header($k, $value);
				}
			}
			if(isset($r['Status']) && strcmp($r['Status'], '200'))
			{
				$desc = array(
					'Failed to negotiate ' . $method . ' with ' . get_class($this),
					'Requested content types:',
					);
				ob_start();
				print_r($req->types);
				$desc[] = ob_get_clean();
				$desc[] = 'Supported content types:';
				ob_start();
				print_r($this->supportedTypes);
				$desc[] = ob_get_clean();
				$req->header('Allow', implode(', ', $this->supportedMethods));
				return $this->error($r['Status'], $req, null, implode("\n\n", $desc));			
			}
		}
		else if(!in_array($method, $this->negotiateMethods))
		{
			$type = '*/*';
		}
		else
		{
			$desc = array(
				'Failed to negotiate ' . $method . ' with ' . get_class($this),
				'Requested content types:',
				);
			ob_start();
			print_r($req->types);
			$desc[] = ob_get_clean();
			$desc[] = 'Supported content types:';
			ob_start();
			print_r($this->supportedTypes);
			$desc[] = ob_get_clean();
			$req->header('Allow', implode(', ', $this->supportedMethods));
			return $this->error($r, $req, null, implode("\n\n", $desc));
		}
		if(self::$willPerformMethod)
		{
			call_user_func(self::$willPerformMethod, $this, $method, $type);
		}
		$r = $this->performMethod($method, $type);
		$this->object = null;
		$this->request = null;
		$this->sessionObject = null;
		return $r;
	}
    
	/**
	 * Attempt to perform content negotiation.
	 *
	 * Returns an associative array containing a set of HTTP-style headers,
	 * or an HTTP status code (method not allowed, not acceptable, etc.) upon
	 * failure.
	 */
    protected function negotiate(Request $req)
    {
		$headers = array('Status' => 200, 'Vary' => array());

		if($this->supportedMethods !== null)
		{
			if(!in_array($req->method, $this->supportedMethods))
			{
				return 405; /* Method Not Allowed */
			}
		}
		$defaultType = null;
		$defaultLanguage = null;
		$this->processAcceptList($this->supportedTypes, 'type', $defaultType);
		$this->processAcceptList($this->supportedLangs, 'lang', $defaultLanguage);
		$alternates = array();
		$uri = $req->resource;        
		/* Perform content negotiation */
		if($this->supportedTypes !== null)
		{
			$l = count($req->suffixes);
			if($l && ($t = MIME::typeForExt($req->suffixes[$l - 1])) !== null)
			{
				$ext = array_pop($req->suffixes);
				$req->explicitSuffix = '.' . $ext;
				$t = MIME::typeForExt($ext);
				$req->types = array(
					$t => array('type' => $t, 'q' => 1),
					);
			}
			$match = array();
			foreach($this->supportedTypes as $k => $value)
			{
				/* Calculate from the accept headers */
				if(isset($req->types[$value['type']]))
				{
					$value['cq'] = $req->types[$value['type']]['q'];
				}
				else if(isset($req->types['*']))
				{
					$value['cq'] = $req->types['*']['q'];
				}
				else
				{
					$value['cq'] = 0;
				}
				/* Add the type to the match list with a sortable key */
				if($value['q'] * $value['cq'])
				{
					$key = sprintf('%1.05f-%s', $value['q'] * $value['cq'], $value['type']);					
					$match[$key] = $value;
				}
				/* Finally, if the type isn't hidden, add it to the list of alternates */
				if(!empty($value['hide']))
				{
					continue;
				}
				if(isset($value['location']))
				{
					/* If there's a specific location, just add it to the alternates list as-is */
					$alternates[] = '{"' . addslashes($value['location']) . '" ' . floatval($value['q']) . ' {type ' . $value['type'] . '}}';
					continue;
				}
				if(isset($value['ext']))
				{
					$ext = '.' . $value['ext'];
				}
				else
				{
					$ext = MIME::extForType($value['type']);				
				}
				if(!strlen($ext))
				{
					continue;
				}
				if(isset($value['lang']))
				{
					$dummy = null;
					$langList = $value['lang'];
					$this->processAcceptList($langList, 'lang', $dummy);
				}
				else
				{
					$langList = $this->supportedLangs;
				}
				if($langList !== null)
				{
					foreach($langList as $lang)
					{
						$lext = '.' . (isset($lang['ext']) ? $lang['ext'] : $lang['lang']);
						$alternates[] = '{"' . addslashes($uri . $lext . $ext) . '" ' . floatval($value['q']) . ' {type ' . $value['type'] . '}{language ' . $lang['lang'] . '}}';
					}
				}
				else
				{
					$alternates[] = '{"' . addslashes($uri . $ext) . '" ' . floatval($value['q']) . ' {type ' . $value['type'] . '}}';
				}
			}
			if(count($alternates))
			{
				$headers['Alternates'] = implode(', ', $alternates);
			}
			krsort($match);
			$type = array_shift($match);
			if($type === null)
			{
				if($defaultType !== null)
				{
					$type = $defaultType;
				}
				else
				{
					$headers['Status'] = 406;
					return $headers;
				}
			}
            if(!isset($type['ext']))
            {
                $type['ext'] = ltrim(MIME::extForType($type['type']), '.');
            }
			$this->negotiatedType = $req->negotiatedType = $type;
			if(isset($type['lang']))
			{
				$this->supportedLangs = $type['lang'];
				$this->processAcceptList($this->supportedLangs, 'lang', $defaultLanguage);				
			}
			$headers['Content-Type'] = $type['type'];
			$headers['Vary'][] = 'Accept';
			if(isset($type['location']))
			{
				$headers['Content-Location'] = $type['location'];
			}
		}
		/* Transform $languages into a form we can use */
		/* Perform content-language negotiation */
		if($this->supportedLangs !== null)
		{
            $headers['Vary'][] = 'Accept-Language';
			if(isset($req->suffixes[0]))
			{
				$suf = array_shift($req->suffixes);
				$lang = null;
				foreach($this->supportedLangs as $value)
				{
					if(!strcmp($suf, $value['lang']))
					{
						$lang = $value['lang'];
						break;
					}
				}
				if($lang === null)
				{
					return 406;
				}
				$req->explicitLang = $lang;
				$req->langs = array($lang => array('lang' => $lang, 'q' => 1));
			}
			$match = array();
			foreach($this->supportedLangs as $value)
			{
				if(isset($req->langs[$value['lang']]))
				{
					$value['cq'] = $req->langs[$value['lang']]['q'];
				}
				else if(isset($req->langs['*']))
				{
					$value['cq'] = $req->langs['*']['q'];
				}
				else
				{
					$value['cq'] = 0;
				}
				/* Add the type to the match list with a sortable key */
				if($value['q'] * $value['cq'])
				{
					$key = sprintf('%1.05f-%s', $value['q'] * $value['cq'], $value['lang']);					
					$match[$key] = $value;
				}
				if(!empty($value['hide']))
				{
					continue;
				}
				if(isset($value['location']))
				{
					/* If there's a specific location, just add it to the alternates list as-is */
					$alternates[] = '{"' . addslashes($value['location']) . '" ' . floatval($value['q']) . ' {language ' . $value['lang'] . '}}';
					continue;
				}
				if($this->supportedTypes !== null)
				{
					/* Allow type negotiation above to populate the Alternates header */
					continue;
				}
				if(isset($value['ext']))
				{
					$ext = '.' . $value['ext'];
				}
				else
				{
					$ext = '.' . $value['lang'];				
				}
				$alternates[] = '{"' . addslashes($uri . $ext) . '" ' . floatval($value['q']) . ' {language ' . $value['lang'] . '}}';
			}
			krsort($match);
			$lang = array_shift($match);
			if($lang === null)
			{
				if(isset($defaultLanguage))
				{
					$lang = $defaultLanguage;
				}
				else
				{
					$headers['Status'] = 406;
					return $headers;
				}
			}
            if(!isset($lang['ext']))
            {
                $lang['ext'] = $lang['lang'];
            }
			$this->negotiatedLang = $req->negotiatedLang = $lang;
			$headers['Content-Language'] = $lang['lang'];
			if(isset($lang['location']))
			{
				$headers['Content-Location'] = $lang['location'];
			}			
		}
		if(count($alternates))
		{
			$headers['Alternates'] = implode(', ', $alternates);
		}
        if(isset($this->negotiatedLang['ext']))
        {
            $uri .= '.' . $this->negotiatedLang['ext'];
        }
        if(isset($this->negotiatedType['ext']))
        {
            $uri .= '.' . $this->negotiatedType['ext'];
        }
        $headers['Content-Location'] = $uri;
		return $headers;    
    }
	
	protected function processAcceptList(&$list, $key, &$default)
	{
		if($list === null)
		{
			return null;
		}
		if(!is_array($list))
		{
			$list = array($list);
		}
		$def = null;
		$map = array();
		foreach($list as $k => $value)
		{
			if(!strcmp($k, 'default'))
			{
				unset($list[$k]);
				$def = $value;
				continue;
			}
			if(is_array($value))
			{
				if(!isset($value['q']))
				{
					$value['q'] = 1;
				}
				if(!isset($value[$key]))
				{
					$value[$key] = $k;
				}
			}
			else
			{
				$value = array('q' => 1, $key => $value);
			}
			$list[$k] = $value;
			$map[$value[$key]] = $value;
		}
		if($def !== null && isset($map[$def]))
		{
			$default = $map[$def];
		}
	}
    
	protected function addSupportedTypes($serialisations)
	{
		foreach($serialisations as $key => $info)
		{
			if(is_numeric($key))
			{
				$this->supportedTypes[] = $info;
			}
			else
			{
				$this->supportedTypes[$key] = $info;
			}
		}
	}
	
	protected function performMethod($method, $type)
	{
		$methodName = 'perform_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $method);
		if(!method_exists($this, $methodName))
		{
			return $this->error(Error::METHOD_NOT_IMPLEMENTED, $this->request, null, 'Method ' . $methodName . ' is not implemented by ' . get_class($this));
		}
		$r = $this->$methodName($type);
		if($r && !in_array($method, $this->noFallThroughMethods))
		{
			$r = $this->perform_GET($type);
		}
		return $r;
	}

	protected function addCrumb(Request $req = null)
	{
		if(!$req)
		{
			$req = $this->request;
		}
		parent::addCrumb($req);
	}
	
	protected function error($code, Request $req = null, $object = null, $detail = null)
	{
		if(!$req)
		{
			$req = $this->request;
		}
		parent::error($code, $req, $object, $detail);
		$this->request = null;
		$this->sessionObject = null;
	}
	
	protected function getObject()
	{
		return true;
	}

	protected function putObject($data)
	{
		return true;
	}

	protected function perform_OPTIONS($type)
	{
		$this->request->header('Allow', implode(',', $this->supportedMethods));
		$this->request->header('Content-length', 0);
		$this->request->header('Content-type');
		if(in_array('PROPFIND', $this->supportedMethods))
		{
			$this->request->header('DAV', 1);
		}
		return false;
	}
	
	protected function perform_HEAD($type)
	{
		return $this->perform_GET($type);
	}
	
	protected function perform_GET($type)
	{
		switch($type)
		{
		case 'text/xml':
		case 'application/xml':
			return $this->perform_GET_XML($type);
		case 'application/json':
			return $this->perform_GET_JSON($type);
		case 'application/x-rdf+json':
		case 'application/rdf+json':
			return $this->perform_GET_RDFJSON($type);
		case 'application/rdf+xml':
			return $this->perform_GET_RDF($type);
		case 'text/turtle':
			return $this->perform_GET_Turtle($type);
		case 'application/x-yaml':
			return $this->perform_GET_YAML($type);
		case 'application/atom+xml':
			return $this->perform_GET_Atom($type);
		case 'text/plain':
			return $this->perform_GET_Text($type);
		case 'text/html':
			return $this->perform_GET_HTML($type);
		case 'text/javascript':
			return $this->perform_GET_JS($type);
		}
		/* Try to construct a method name based on the MIME type */
		$ext = preg_replace('![^A-Z]!', '_', strtoupper(MIME::extForType($type)));
		if(strlen($ext))
		{
			$methodName = 'perform_GET' . $ext;
			if(method_exists($this, $methodName))
			{
				return $this->$methodName();
			}
		}
		return $this->serialise($type);
	}

	protected function perform_GET_JS($type = 'text/javascript')
	{
		return $this->perform_GET_JSON($type);
	}

	protected function serialise($type, $returnBuffer = false, $sendHeaders = true, $reportError = true)
	{
		if(!($this->object instanceof ISerialisable))
		{
			if($reportError)
			{
				$this->error(Error::TYPE_NOT_SUPPORTED, $this->request, null, 'Object is not serialisable (requested ' . $type . ')');
			}
			return false;
		}
		if(false === ($r = $this->object->serialise($type, $returnBuffer, $this->request, $sendHeaders)))
		{
			if($reportError)
			{
				$this->error(Error::METHOD_NOT_IMPLEMENTED, $this->request, null, 'Serialisable object does not support ' . $type);
			}
		}
		return $r;
	}
	
	protected function perform_GET_XML($type = 'text/xml')
	{
		return $this->serialise($type);
	}
	
	protected function perform_GET_Text($type = 'text/plain')
	{
		return $this->serialise($type);
	}

	protected function perform_GET_HTML($type = 'text/html')
	{
		return $this->serialise($type);
	}

	protected function perform_GET_JSON($type = 'application/json')
	{
		if(isset($this->request->query['jsonp']))
		{
			$prefix = $this->request->query['jsonp'] . '(';
			$suffix = ')';
			$type = 'text/javascript';
		}
		else if(isset($this->request->query['callback']))
		{
			$prefix = $this->request->query['callback'] . '(';
			$suffix = ')';
			$type = 'text/javascript';
		}
		else
		{
			$prefix = null;
			$suffix = null;
		}
		$this->request->header('Content-type', $type);
		echo $prefix;
		if(isset($this->object))
		{
			if(!($this->object instanceof ISerialisable) || $this->object->serialise($type) === false)
			{
				echo json_encode($this->object);
			}
		}
		else if(isset($this->objects))
		{
			if(!($this->objects instanceof ISerialisable) || $this->objects->serialise($type) === false)
			{
				echo json_encode($this->objects);
			}
		}
		echo $suffix;
	}

	protected function perform_GET_RDFJSON()
	{
		if(isset($this->request->query['jsonp']))
		{
			$type = 'text/javascript';
			$prefix = $this->request->query['jsonp'] . '(';
			$suffix = ')';
		}
		else if(isset($this->request->query['callback']))
		{
			$type = 'text/javascript';
			$prefix = $this->request->query['callback'] . '(';
			$suffix = ')';
		}
		else
		{
			$type = 'application/rdf+json';
			$prefix = null;
			$suffix = null;
		}
		$this->request->header('Content-type', $type);
		echo $prefix;
		if(isset($this->object))
		{
			if(!($this->object instanceof ISerialisable) || $this->object->serialise($type) === false)
			{					
				echo json_encode($this->object);
			}
		}
		else if(isset($this->objects))
		{
			echo json_encode($this->objects);
		}
		echo $suffix;
	}

	protected function perform_GET_RDF($type = 'application/rdf+xml')
	{
		return $this->serialise($type);
	}

	protected function perform_GET_Turtle($type = 'text/turtle')
	{
		return $this->serialise($type);
	}
	
	protected function perform_GET_YAML($type = 'application/x-yaml')
	{
		return $this->serialise($type);
	}
	
	protected function perform_GET_Atom($type = 'application/atom+xml')
	{
		return $this->serialise($type);
	}

	protected function perform_POST($type)
	{
		return $this->error(Error::UNSUPPORTED_MEDIA_TYPE, null, null, get_class($this) . '::perform_POST() cannot handle this request');
	}

	protected function perform_PUT($type)
	{
		switch($this->request->contentType)
		{
			case 'application/x-www-form-urlencoded':
			case 'multipart/form-data':
				return $this->putObject($this->request->postData);
			case 'application/json':
				return $this->putObject($this->request->postData);
		}
		return $this->error(Error::UNSUPPORTED_MEDIA_TYPE, null, null, $this->request->contentType . ' is not supported by ' . get_class($this) . '::perform_PUT()');
	}
	
	protected function perform_DELETE($type)
	{
	}
	
	protected function perform_PROPFIND($type)
	{
		if(($r = $this->request->consume()) !== null)
		{
			return $this->error(Error::OBJECT_NOT_FOUND);
		}
		if(!isset($this->request->data['davCollection']))
		{
			$this->request->data['davCollection'] = $this->davCollection;
		}
		$this->request->postData = file_get_contents('php://input');		
		try
		{
			$doc = new DOMDocument();
			$doc->loadXML($this->request->postData);
		}
		catch(Exception $e)
		{
			return $this->error(Error::BAD_REQUEST, null, null, $e);
		}
		$root = $doc->firstChild;
		$name = URI::qualify($root);
		if(strcmp($name, 'DAV:propfind'))
		{
			return $this->error(Error::BAD_REQUEST, null, null, 'Root element is not DAV:propfind');
		}
		$properties = array();
		for($c = $root->firstChild; $c !== null; $c = $c->nextSibling)
		{
			if(!($c instanceof DOMElement))
			{
				continue;
			}
			$name = URI::qualify($c);
			if(!strcmp($name, 'DAV:allprop'))
			{
				$properties = array('*');
				break;
			}
			if(!strcmp($name, 'DAV:prop'))
			{
				for($pc = $c->firstChild; $pc !== null; $pc = $pc->nextSibling)
				{
					if(!($pc instanceof DOMElement))
					{
						continue;
					}
					$properties[] = URI::qualify($pc);
				}
			}				
		}
		$depth = array();
		if(isset($this->request->headers['Depth']))
		{
			$depth = explode(',', $this->request->headers['Depth']);
		}
		if(!count($depth))
		{
			$depth = array('undefined');
		}		
		$this->request->header('Status', 'HTTP/1.1 207 Multi-Status');
		$this->request->header('Content-Type', 'text/xml');
		writeLn('<?xml version="1.0" encoding="UTF-8" ?>');
		writeLn('<dav:multistatus xmlns:dav="DAV:">');
		$propList = array();
		$rootProps = null;
		if(!in_array('noroot', $depth))
		{
			$rootProps = $this->davPropTranslate(null, $this->request->data, $properties);
		}
		if((in_array('1', $depth) || in_array('infinite', $depth)) &&
		   !empty($this->request->data['davCollection']))
		{
			$propList = $this->davPropAlternates($properties);
		}
		if($rootProps !== null)
		{
			$propList[''] = $rootProps;
		}
		if(strlen($this->request->explicitSuffix))
		{
			/* Just emit the information for this one specific resource */
			if(isset($propList[$this->request->explicitSuffix]))
			{				
				$this->davPropEmit($this->request->pageUri . INDEX_RESOURCE_NAME . $this->request->explicitSuffix, $propList[$this->request->explicitSuffix], $properties);
			}
			else
			{
				writeLn('<dav:response>');
				writeLn('<dav:href>' . _e($this->request->pageUri . INDEX_RESOURCE_NAME . $this->request->explicitSuffix) . '</dav:href>');
				writeLn('<dav:propstat>');
				writeLn('<dav:status>404 Not Found</dav:status>');
				writeLn('</dav:propstat>');
				writeLn('</dav:response>');
			}
		}
		else
		{
			foreach($propList as $key => $info)
			{
				if(!empty($info['_hide']))
				{
					continue;
				}
				$href = (strlen($key) ? ($this->request->pageUri . INDEX_RESOURCE_NAME . $key) : $this->request->pageUri);
				$this->davPropEmit($href, $info, $properties);
			}
			$this->davPropChildren($properties);
		}
		writeLn('</dav:multistatus>');
		$this->request->complete();
	}
	
	/* Emit a set of DAV properties */
	protected function davPropEmit($resource, $properties, $requested)
	{
		writeLn('<dav:response>');
		writeLn('<dav:href>' . _e($resource) . '</dav:href>');
		writeLn('<dav:propstat>');
		
		writeLn('<dav:prop>');
		$allprop = in_array('*', $requested);
		foreach($properties as $prop => $value)
		{
			if(!$allprop && !in_array($prop, $requested))
			{
				continue;
			}
			$el = URI::contractUri($prop, true, true);
			$inner = '';
			if(!strcmp($el['ns'], 'DAV:'))
			{
				$xmlns = '';
				$el['prefix'] = 'dav';
				$el['qname'] = 'dav:' . $el['local'];
			}
			else
			{
				$xmlns = ' xmlns:' . $el['prefix'] . '="' . _e($el['ns']) . '"';
			}
			if(is_array($value))
			{
				$vel = URI::contractUri($value['@'], true, true);
				if(!strcmp($vel['ns'], $el['ns']))
				{
					$vxmlns = '';
					$vel['prefix'] = $el['prefix'];
					$vel['qname'] = $el['prefix'] . ':' . $vel['local'];
				}
				else if(!strcmp($vel['ns'], 'dav:'))
				{
					$vxmlns = '';
					$vel['prefix'] = 'dav';
					$vel['qname'] = 'dav:' . $vel['local'];
				}
				else
				{
					$vxmlns = ' xmlns:' . $vel['prefix'] . '="' . _e($vel['ns']) . '"';
				}
				$inner = '<' . $vel['qname'] . $vxmlns . ' />';
			}
			else
			{
				$inner = _e($value);
			}
			writeLn('<' . $el['qname'] . $xmlns . '>' . $inner . '</' . $el['qname'] . '>');
		}
		writeLn('</dav:prop>');
		writeLn('<dav:status>200 OK</dav:status>');
		writeLn('</dav:propstat>');
		writeLn('</dav:response>');
	}
	
	/* Translate and return the DAV properties for our various alternates */
	protected function davPropAlternates($properties, $types = null, $uri = null)
	{
		if($types === null)
		{
			$types = $this->supportedTypes;
		}
		if($uri === null)
		{
			$uri = $this->request->resource;
		}
		$propList = array();
		foreach($types as $k => $value)
		{
			if(is_array($value))
			{
				if(!isset($value['q']))
				{
					$value['q'] = 1;
				}
				if(!isset($value['type']))
				{
					$value['type'] = $k;
				}
			}
			else
			{
				$value = array('q' => 1, 'type' => $value);
			}
			if(isset($value['ext']))
			{
				$l = $uri . '.' . $value['ext'];
				$value['_href'] = $l;
				$key = '.' . $value['ext'];
			}
			else
			{
				$e = MIME::extForType($value['type']);
				if(strlen($e))
				{
					$l = $uri . $e;
					$value['_href'] = $l;
					$key = $e;
				}
				else
				{
					continue;
				}
			}
			$propList[$key] = $this->davPropTranslate($value['_href'], $value, $properties);
		}
		return $propList;
	}

	/* Emit the DAV properties for any children of this collection */
	protected function davPropChildren($properties)
	{
		foreach($this->routes as $name => $info)
		{
			if(substr($name, 0, 2) == '__')
			{
				continue;
			}
			if(isset($info['davHide']))
			{
				if(!empty($info['davHide']))
				{
					continue;
				}
			}
			else if(!empty($info['hide']))
			{
				continue;
			}
			if(empty($info['davCollection']) && isset($info['supportedTypes']))
			{
				$propList = $this->davPropAlternates($properties, $info['supportedTypes'], $this->request->pageUri . $name);
				foreach($propList as $type => $props)
				{					
					if(!empty($props['_hide']))
					{
						continue;
					}
					$this->davPropEmit($props['_href'], $props, $properties);
				}
			}
			else
			{
				$propList = $this->davPropTranslate($name, $info, $properties);
				$this->davPropEmit($name, $propList, $properties);
			}
		}
	}

	/* Translate internal data into DAV properties */
	protected function davPropTranslate($resource, $data, $requested = null)
	{
		$props = array();
		/* The '_href' and '_hide' properties are used internally */
		if(isset($data['_href']))
		{
			$props['_href'] = $data['_href'];
		}
		if(isset($data['davHide']))
		{
			$props['_hide'] = $data['davHide'];
		}
		else if(!empty($data['hide']))
		{
			$props['_hide'] = $data['hide'];
		}
		if(!empty($data['davCollection']))
		{
			$props['DAV:resourcetype'] = array('@' => 'DAV:collection');
		}
		if(isset($data['title']))
		{
			$props['DAV:displayname'] = $data['title'];
		}
		if(isset($data['type']))
		{
			$props['DAV:getcontenttype'] = $data['type'];
		}
		if(isset($data['size']))
		{
			$props['DAV:getcontentlength'] = $data['size'];
		}
		if(isset($data['etag']))
		{
			$props['DAV:getetag'] = $data['etag'];
		}
		if(isset($data['modified']))
		{
			$props['DAV:getlastmodified'] = strftime('%a, %d %b %Y %H:%M:%S GMT', strtotime($data['modified']));		
		}
		if(isset($data['created']))
		{
			$props['DAV:getcreationdate'] = strftime('%Y-%m-%dT%H:%M:%SZ', strtotime($data['created']));		
		}
		return $props;
	}

	public function __get($name)
	{
		if($name == 'session')
		{
			if(!$this->sessionObject && $this->request)
			{
				$this->sessionObject = $this->request->session;
			}
			return $this->sessionObject;
		}
	}
}

/**
 * Interface implemented by command-line routable classes.
 */
interface ICommandLine
{
	function main($args);
}

/**
 * Encapsulation of a command-line interface handler.
 */
abstract class CommandLine extends Proxy implements ICommandLine
{
	const no_argument = 0;
	const required_argument = 1;
	const optional_argument = 2;

	protected $supportedMethods = array('__CLI__');
	protected $supportedTypes = array('text/plain');
	protected $args;
	protected $options = array();
	protected $minArgs = 0;
	protected $maxArgs = null;
	
	protected $optopt = -1;
	protected $optarg = null;
	protected $optind = 0;
	protected $opterr = true;
	protected $longopt = null;
	
	protected function getObject()
	{
		$this->args = $this->request->params;
		if(!($this->checkargs($this->args)))
		{
			return false;
		}
		return true;
	}
	
	protected function perform___CLI__()
	{
		if(0 === ($r = $this->main($this->args)))
		{
			exit(0);
		}
		if($r === true)
		{
			exit(0);
		}
		if(!is_numeric($r) || $r < 0)
		{
			$r = 1;
		}
		exit($r);
	}
	
	protected function checkargs(&$args)
	{
		while(($c = $this->getopt($args)) != -1)
		{
			if($c == '?')
			{
				$this->usage();
				return false;
			}
		}
		if($this->minArgs !== null && count($args) < $this->minArgs)
		{
			$this->usage();
			return false;
		}
		if($this->maxArgs !== null && count($args) > $this->maxArgs)
		{
			$this->usage();
			return false;
		}
		return true;
	}
	
	protected function usage()
	{
		if(isset($this->usage))
		{
			echo "Usage:\n\t" . $this->usage . "\n";
		}
		else
		{
			echo "Usage:\n\t" . str_replace('/', ' ', $this->request->pageUri) . (count($this->options) ? ' [OPTIONS]' : '') . ($this->minArgs ? ' ...' : '') . "\n";
		}
		$f = false;
		foreach($this->options as $long => $opt)
		{
			if(isset($opt['description']) && (!is_numeric($long) || isset($opt['value'])))
			{
				if(is_numeric($long))
				{
					$s = '-' . $opt['value'];					
				}
				else
				{
					$s = '--' . $long . (isset($opt['value']) ? ', -' . $opt['value'] : '');
				}
				if(!$f)
				{
					echo "\nOPTIONS is one or more of:\n";
					$f = true;
				}
				echo sprintf("\t%-25s  %s\n", $s, $opt['description']);
			}
		}
		exit(1);
	}
	
	protected function getopt(&$args)
	{
		$null = null;
		$this->optopt = -1;
		$this->optarg = null;
		$this->longopt =& $null;
		
		while($this->optind < count($args))
		{
			$arg = $args[$this->optind];
			if(!strcmp($arg, '--'))
			{
				return -1;
			}
			if(substr($arg, 0, 1) != '-')
			{
				$this->optind++;
				continue;
			}
			if(!strcmp($arg, '-'))
			{
				$this->optind++;
				continue;
			}
			if(substr($arg, 1, 1) == '-')
			{
				$arglen = 1;
				$arg = substr($arg, 2);
				$x = explode('=', $arg, 2);
				$longopt = $x[0];
				$optname = '--' . $longopt;
				if(isset($x[1]))
				{
					$this->optarg = $x[1];
				}
				else
				{
					$this->optarg = null;
				}
				if(isset($this->options[$longopt]))
				{
					$this->longopt =& $this->options[$longopt];
				}
				else
				{
					if($this->opterr)
					{
						echo "unrecognized option: `--$longopt'\n";
					}
					return '?';
				}
			}
			else
			{
				$ch = substr($arg, 1, 1);
				$optname = '-' . $ch;
				$arglen = 0;
				foreach($this->options as $k => $opt)
				{
					if(isset($opt['value']) && !strcmp($opt['value'], $ch))
					{
						$this->longopt =& $this->options[$k];
						break;
					}
				}
				if($this->longopt === null)
				{
					if($this->opterr)
					{
						echo "unrecognized option `-$ch'\n";
					}
					return '?';
				}
				if(!empty($this->longopt['has_arg']))
				{
					if(strlen($arg) > 2)
					{
						$this->optarg = substr($arg, 2);
						$arglen = 1;
					}
				}
				if(!$arglen)
				{
					if(strlen($arg) > 2)
					{
						$args[$this->optind] = '-' . substr($arg, 2);
					}
					else
					{
						$arglen++;
					}
				}
			}
			if($this->optarg === null)
			{
				if(!empty($this->longopt['has_arg']))
				{
					$c = $this->optind;
					if($c + 1 < count($args))
					{
						if(substr($args[$c + 1], 0, 1) != '-')
						{
							$this->optarg = $args[$c + 1];
							$arglen++;
						}
					}					
					if($this->optarg === null && $this->longopt['has_arg'] == self::required_argument)
					{
						if($this->opterr)
						{
							echo "option `$optname' requires an argument\n";
						}
						return '?';
					}
				}
			}
			else if(empty($this->longopt['has_arg']))
			{
				if($this->opterr)
				{
					echo "option `$optname' doesn't allow an argument\n";
				}
				return '?';
			}			
			if($this->optarg === null)
			{
				$this->longopt['flag'] = true;		
			}
			else
			{
				$this->longopt['flag'] = $this->optarg;
			}
			if(isset($this->longopt['value']))
			{
				$this->optopt = $this->longopt['value'];
			}
			else
			{
				$this->optopt = $optname;
			}
			if($arglen)
			{
				array_splice($args, $this->optind, $arglen);
			}
			return $this->optopt;
		}
		return -1;
	}

	protected function err($message)
	{
		$args = func_get_args();
		$this->request->err('Error: ' . implode(' ', $args) . "\n");
	}
}
