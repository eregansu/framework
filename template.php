<?php

/* Eregansu: Template engine
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
 * @include uses('template');
 * @since Available in Eregansu 1.0 and later. 
 */

if(!defined('TEMPLATES_PATH')) define('TEMPLATES_PATH', 'templates');

/**
 * Eregansu web page templating.
 */
class Template
{
	public $path;
	public $request;
	public $vars = array();
	protected $filename;
	protected $skin;
		
	public function __construct(Request $req, $filename, $skin = null, $fallbackSkin = null, $theme = null, $fallbackTheme = null)
	{
		$this->request = $req;
		$this->skin = $this->fallback($skin, defined('DEFAULT_SKIN') ? DEFAULT_SKIN : '', $fallbackSkin, 'default');
		$this->theme = $this->fallback($theme, defined('DEFAULT_THEME') ? DEFAULT_THEME : '', $fallbackTheme, $this->skin);
		$this->reset();
		if(strrchr($filename, '.') === false)
		{
			$filename .= (isset($req->negotiatedLang) ? '.' . $req->negotiatedLang['lang'] : '') . '.phtml';
		}
		$this->filename = $filename;
		$this->path = $this->vars['skin_path'] . $filename;
	}
	
	protected function fallback(/*...*/)
	{
		$args = func_get_args();
		do
		{
			$s = array_shift($args);
		}
		while(!strlen($s) && count($args));
		return $s;
	}
	
	/* Merge the contents of $vars with the existing template variables */
	public function setArray($vars)
	{
		$this->vars = array_merge($vars, $this->vars);
	}

	public function setArrayRef(&$vars)
	{
		$this->vars =& $vars;
	}
	
	/* Reset the template variables to their initial values */
	public function reset()
	{
		$this->vars = array();
		$this->vars['templates_path'] = $this->request->siteRoot . TEMPLATES_PATH . '/';
		$this->vars['templates_iri'] = (defined('STATIC_IRI') ? STATIC_IRI : $this->request->root . TEMPLATES_PATH . '/');
		$this->setTemplatePathVars('skin', $this->skin);
		$this->setTemplatePathVars('theme', $this->theme);
	}
	
	protected function setTemplatePathVars($prefix, $path)
	{
		if(substr($path, 0, 1) == '/')
		{
			if(substr($this->skin, -1) != '/') $this->skin .= '/';
			$this->vars[$prefix . '_path'] = $this->skin;			
			$this->vars[$prefix] = basename($this->skin);
			if(!strncmp($this->skin, $this->vars['templates_path'], strlen($this->vars['templates_path'])))
			{
				$this->vars[$prefix . '_iri'] = $this->vars['templates_iri'] . substr($path, strlen($this->vars['templates_path']));
			}
			else if(!strncmp($path, $this->request->siteRoot, strlen($this->request->siteRoot)))
			{
				$this->vars[$prefix . '_iri'] = $this->request->root . substr($path, strlen($this->request->siteRoot));
			}
			else
			{
				$this->vars[$prefix . '_iri'] = $this->vars['templates_iri'] . $path;
			}
		}
		else
		{
			$this->vars[$prefix . '_path'] = $this->vars['templates_path'] . $path . '/';
			$this->vars[$prefix . '_iri'] = $this->vars['templates_iri'] . $path . '/';
			$this->vars[$prefix] = $path;
		}	
	}
	
	/* Render a template */
	public function process()
	{
		global $_EREGANSU_TEMPLATE;
		
		$__ot = !empty($_EREGANSU_TEMPLATE);
		extract($this->vars, EXTR_REFS);
		$_EREGANSU_TEMPLATE = true;
		require($this->path);
		$_EREGANSU_TEMPLATE = $__ot;
	}

	/* Libraries */

	public function useJQuery($version = '1.7.2')
	{
		$root = $this->request->root;
		if(defined('SCRIPTS_IRI')) $root = SCRIPTS_IRI;
		if(defined('SCRIPTS_USE_GAPI')) $root = 'http://ajax.googleapis.com/ajax/libs/';
		$this->vars['scripts']['jquery'] = $root . 'jquery/' . $version . '/jquery.min.js';
	}

	public function useRequireJS($version = '1.0.7')
	{
		$root = $this->request->root;
		if(defined('SCRIPTS_IRI')) $root = SCRIPTS_IRI;
		if(defined('SCRIPTS_USE_CDNJS')) $root = 'http://cdnjs.cloudflare.com/ajax/libs/';
		$this->vars['scripts']['require.js'] = $root . 'require.js/' . $version . '/require.min.js';
	}
	
	public function useGlitter($module)
	{
		$this->useJQuery();
		$root = $this->request->root;
		if(defined('SCRIPTS_IRI')) $root = SCRIPTS_IRI;
		$this->vars['scripts']['glitter/' . $module] = $root . 'glitter/' . $module . '.js';
	}
	
	public function useGlow($module = 'core', $version = '1.7.0')
	{
		static $hasScript = array(
			'1.7.0' => array('core', 'widgets'),
			'2.0.0b1' => array('core'),
			);
		static $hasCSS = array(
			'1.7.0' => array('widgets'),
			'2.0.0b1' => array('ui'),
			);
		static $isFlat = array('2.0.0b1');

		$root = $this->request->root;
		if(defined('SCRIPTS_IRI')) $root = SCRIPTS_IRI;
		if($module != 'core' && !isset($this->vars['scripts']['glow-core']))
		{
			$this->useGlow('core', $version);
		}
		$flat = in_array($version, $isFlat);
		if(isset($hasScript[$version]) && in_array($module, $hasScript[$version]))
		{
			if($flat)
			{				
				$this->vars['scripts']['glow-' . $module] = $root . 'glow/' . $version . '/' . ($module == 'core' ? 'glow' : $module) . '.js';
			}
			else
			{
				$this->vars['scripts']['glow-' . $module] = $root . 'glow/' . $version . '/' . $module . '/' . $module . '.js';
			}
		}
		if(isset($hasCSS[$version]) && in_array($module, $hasCSS[$version]))
		{
			if($flat)
			{
				$path = $root . 'glow/' . $version . '/' . $module . '.css';
			}
			else
			{
				$path = $root . 'glow/' . $version . '/' . $module . '/' . $module . '.css';
			}			
			$this->vars['links']['glow-' . $module] = array(
				'rel' => 'stylesheet',
				'type' => 'text/css', 
				'href' => $path,
				'media' => 'screen');
		}
	}
	
	public function useChromaHash()
	{
		$this->useJQuery();
		$root = $this->request->root;
		if(defined('SCRIPTS_IRI')) $root = SCRIPTS_IRI;
		$this->vars['scripts']['chroma-hash'] = $root . 'Chroma-Hash/chroma-hash.js';
	}

	/* Helpers which can be invoked by a template */

	protected function title()
	{
		if(isset($this->vars['page_title']))
		{
			echo '<title>' . _e($this->vars['page_title']) . '</title>' . "\n";
		}
	}

	protected function links()
	{
		if(!isset($this->vars['links'])) return;
		foreach($this->vars['links'] as $link)
		{
			$h = array('<link');
			foreach($link as $k => $v)
			{
				$h[] = $k . '="' . _e($v) . '"';
			}
			echo implode(' ', $h) . '>' . "\n";
		}
	}

	protected function scripts()
	{
		if(!isset($this->vars['scripts'])) return;
		foreach($this->vars['scripts'] as $script)
		{
			echo '<script src="' . _e($script) . '"></script>' . "\n";
		}
	}
}

