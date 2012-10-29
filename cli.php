<?php

/* Eregansu: Additional command-line support
 *
 * Copyright 2009-2011 Mo McRoberts.
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
 * @year 2009-2011
 * @since Available in Eregansu 1.0 and later. 
 */

/**
 * Implements the default 'help' command-line route
 */
class CliHelp extends CommandLine
{
	public function main($args)
	{
		if(!isset($this->request->data['_routes']))
		{
			echo "No help available\n";
			return;
		}
		echo "Available commands:\n";
		$routes = $this->request->data['_routes'];
		ksort($routes);
		foreach($routes as $cmd => $info)
		{
			if(substr($cmd, 0, 1) == '_') continue;
			if(!isset($info['description'])) continue;
			echo sprintf("  %-25s  %s\n", $cmd, $info['description']);
		}
	}
}
