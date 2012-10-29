<?php

/*
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

if(!defined('DEBUG_URI_HANDLERS'))
{
	define('DEBUG_URI_HANDLERS', true);
}

global $BUILTIN_USERS;

$BUILTIN_USERS = array(
	'admin' => array(
		'password' => '$1$6ftG5PQK$weoRp2WxncI/6ufIoYSOs1',
		),
	);

class TestAuthBuiltinBasic extends TestHarness
{
	public function main()
	{
		global $request;

		$identity = new URI('builtin:admin');
		$authData = new URI('basic:testpass');
		
		$mech = Auth::mechanism($authData);
		if(!$mech)
		{
			echo "Failed to obtain mechanism for " . $authData . "\n";
			return false;
		}
		try
		{
			$r = $mech->verifyAuth($request, $identity, $authData, '/');
			if($r === true || $r instanceof AuthToken)
			{
				return true;
			}
		}
		catch(AuthError $e)
		{
			$r = $e;
		}
		if($r === false)
		{
			$r = 'false';
		}
		echo get_class($mech) . "::verifyAuth() returned " . $r . "\n";
		if($r instanceof AuthError)
		{
			echo "Reason (from " . get_class($r->engine) . "): " . $r->reason . "\n";
		}
		return false;
	}
}

return 'TestAuthBuiltinBasic';