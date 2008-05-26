<?php

/**
 * @package sapphire
 * @subpackage control
 */

/**
 * Director is responsible for processing URLs, and providing environment information.
 * 
 * The most important part of director is {@link Director::direct()}, which is passed a URL and will execute the appropriate
 * controller.
 * 
 * Director also has a number of static methods that provide information about the environment, such as {@link Director::set_environment_type()}.
 *
 * @package sapphire
 * @subpackage control
 * @see Director::direct(),Director::addRules(),Director::set_environment_type()
 */
class Director {
	
	static private $urlSegment;
	
	static private $urlParams;

	static private $rules = array();
	
	static $siteMode;
	
	static $alternateBaseFolder;

	static $alternateBaseURL;
	
	static $dev_servers = array(
		'localhost',
		'127.0.0.1'
	);
	
	static $test_servers = array();
	
	static protected $environment_type;

	/** 
	 * Sets the site mode (if it is the public site or the cms), 
	 * and runs registered modules. 
 	 */ 
	static protected $callbacks;

	function __construct() {
		if(isset($_GET['debug_profile'])) Profiler::mark("Director", "construct");
		Session::addToArray('history', substr($_SERVER['REQUEST_URI'], strlen(Director::baseURL())));
		if(isset($_GET['debug_profile'])) Profiler::unmark("Director", "construct");
	}

	/**
	 * Return a URL from this user's navigation history.
	 * @param pagesBack The number of pages back to go.  The default, 1, returns the previous
	 * page.
	 */
	static function history($pagesBack = 1) {
		return Session::get('history.' . sizeof(Session::get('history')) - $pagesBack - 1);
	}


	/**
	 * Add URL matching rules to the Director.
	 * 
	 * The director is responsible for turning URLs into Controller objects.  It does thi
	 * 
	 * @param $priority The priority of the rules; higher values will get your rule checked first.  
	 * We recommend priority 100 for your site's rules.  The built-in rules are priority 10, standard modules are priority 50.
	 */
	static function addRules($priority, $rules) {
		Director::$rules[$priority] = isset(Director::$rules[$priority]) ? array_merge($rules, (array)Director::$rules[$priority]) : $rules;
	}

	/**
	 * Process the given URL, creating the appropriate controller and executing it.
	 * 
	 * This method will:
	 *  - iterate over all of the rules given in {@link Director::addRules()}, and find the first one that matches.
	 *  - instantiate the {@link Controller} object required by that rule, and call {@link Controller::setURLParams()} to give the URL paramters to the controller.
	 *  - link the Controller's session to PHP's main session, using {@link Controller::setSession()}.
	 *  - call {@link Controller::run()} on that controller
	 *  - save the Controller's session back into PHP's main session.
	 *  - output the response to the browser, using {@link HTTPResponse::output()}.
	 * 
	 * @param $url String, the URL the user is visiting, without the querystring.
	 * @uses getControllerForURL() rule-lookup logic is handled by this.
	 * @uses Controller::run() Controller::run() handles the page logic for a Director::direct() call.
	 */
	function direct($url) {
		if(isset($_GET['debug_profile'])) Profiler::mark("Director","direct");
		$controllerObj = Director::getControllerForURL($url);
		
		if(is_string($controllerObj) && substr($controllerObj,0,9) == 'redirect:') {
			$response = new HTTPResponse();
			$response->redirect(substr($controllerObj, 9));
			$response->output();
		} else if($controllerObj) {
			// Load the session into the controller
			$controllerObj->setSession(new Session($_SESSION));
		
			$response = $controllerObj->run(array_merge((array)$_GET, (array)$_POST, (array)$_FILES));
			
			
			$controllerObj->getSession()->inst_save();

			if(isset($_GET['debug_profile'])) Profiler::mark("Outputting to browser");
			$response->output();
			if(isset($_GET['debug_profile'])) Profiler::unmark("Outputting to browser");
			
		}
		if(isset($_GET['debug_profile'])) Profiler::unmark("Director","direct");
	}
	
	/**
	 * Test a URL request, returning a response object.
	 * 
	 * This method is the counterpart of Director::direct() that is used in functional testing.  It will execute the URL given,
	 * 
	 * @param $url The URL to visit
	 * @param $post The $_POST & $_FILES variables
	 * @param $session The {@link Session} object representing the current session.  By passing the same object to multiple
	 * calls of Director::test(), you can simulate a peristed session.
	 * 
	 * @uses getControllerForURL() The rule-lookup logic is handled by this.
	 * @uses Controller::run() Controller::run() handles the page logic for a Director::direct() call.
	 */
	function test($url, $post = null, $session = null) {
        $getVars = array();
		if(strpos($url,'?') !== false) {
			list($url, $getVarsEncoded) = explode('?', $url, 2);
            parse_str($getVarsEncoded, $getVars);
		}

		$existingRequestVars = $_REQUEST;
		$existingGetVars = $_GET;
		$existingPostVars = $_POST;
		$existingSessionVars = $_SESSION;

		$_REQUEST = $existingRequestVars;
		$_GET = $existingGetVars;
		$_POST = $existingPostVars;
		$_SESSION = $existingSessionVars;		

		$_REQUEST = array_merge((array)$getVars, (array)$post);
		$_GET = (array)$getVars;
		$_POST = (array)$post;
		$_SESSION = $session ? $session->inst_getAll() : array(); 
		
		$controllerObj = Director::getControllerForURL($url);
		
		// Load the session into the controller
		$controllerObj->setSession($session ? $session : new Session(null));

		if(is_string($controllerObj) && substr($controllerObj,0,9) == 'redirect:') {
			user_error("Redirection not implemented in Director::test", E_USER_ERROR);
			
		} else if($controllerObj) {
			$response = $controllerObj->run( array_merge($getVars, (array)$post) );
			$_REQUEST = $existingRequestVars;
			$_GET = $existingGetVars;
			$_POST = $existingPostVars;
			$_SESSION = $existingSessionVars;
			return $response;
		}
	}
		
		
	/**
	 * Returns the controller that should be used to handle the given URL.
	 * @todo More information about director rules.
	 */
	static function getControllerForURL($url) {
		if(isset($_GET['debug_profile'])) Profiler::mark("Director","getControllerForURL");
		$url = preg_replace( array( '/\/+/','/^\//', '/\/$/'),array('/','',''),$url);
		$urlParts = split('/+', $url);

		krsort(Director::$rules);

		if(isset($_REQUEST['debug'])) Debug::show(Director::$rules);

		foreach(Director::$rules as $priority => $rules) {
			foreach($rules as $pattern => $controller) {
				$patternParts = explode('/', $pattern);
				$matched = true;
				$arguments = array();
				foreach($patternParts as $i => $part) {
					$part = trim($part);
					if(isset($part[0]) && $part[0] == '$') {
						$arguments[substr($part,1)] = isset($urlParts[$i]) ? $urlParts[$i] : null;
						if($part == '$Controller' && !class_exists($arguments['Controller'])) {
							$matched = false;
							break;
						}

					} else if(!isset($urlParts[$i]) || $urlParts[$i] != $part) {
						$matched = false;
						break;
					}
				}
				if($matched) {

					if(substr($controller,0,2) == '->') {
						if(isset($_REQUEST['debug']) && $_REQUEST['debug'] == 1) Debug::message("Redirecting to $controller");

						if(isset($_GET['debug_profile'])) Profiler::unmark("Director","getControllerForURL");
						
						return "redirect:" . Director::absoluteURL(substr($controller,2), true);

					} else {
						if(isset($arguments['Controller']) && $controller == "*") {
							$controller = $arguments['Controller'];
						}

						if(isset($_REQUEST['debug'])) Debug::message("Using controller $controller");
						if(isset($arguments['Action'])) {
							$arguments['Action'] = str_replace('-','',$arguments['Action']);
						}
						if(isset($arguments['Action']) && ClassInfo::exists($controller.'_'.$arguments['Action']))
							$controller = $controller.'_'.$arguments['Action'];

						Director::$urlParams = $arguments;
						$controllerObj = new $controller();

						$controllerObj->setURLParams($arguments);

						if(isset($arguments['URLSegment'])) self::$urlSegment = $arguments['URLSegment'] . "/";

						if(isset($_GET['debug_profile'])) Profiler::unmark("Director","getControllerForURL");
						
						return $controllerObj;
					}
				}
			}
		}
	}

	/**
	 * Returns the urlParam with the given name
	 */
	static function urlParam($name) {
		if(isset(Director::$urlParams[$name])) return Director::$urlParams[$name];
	}
	
	/**
	 * Returns an array of urlParams
	 */
	static function urlParams() {
		return Director::$urlParams;
	}

	/**
	 * Returns the dataobject of the current page.
	 * This will only return a value if you are looking at a SiteTree page
	 */
	static function currentPage() {
		if(isset(Director::$urlParams['URLSegment'])) {
			$SQL_urlSegment = Convert::raw2sql(Director::$urlParams['URLSegment']);
			if (Translatable::is_enabled()) {
				return Translatable::get_one("SiteTree", "URLSegment = '$SQL_urlSegment'");
			} else {
				return DataObject::get_one("SiteTree", "URLSegment = '$SQL_urlSegment'");
			}
		} else {
			return Controller::curr();
		}
	}

	/**
	 * Turns the given URL into an absolute URL.
	 * @todo Document how relativeToSiteBase works
	 */
	static function absoluteURL($url, $relativeToSiteBase = false) {
		if(strpos($url,'/') === false && !$relativeToSiteBase) $url = dirname($_SERVER['REQUEST_URI'] . 'x') . '/' . $url;

	 	if(substr($url,0,4) != "http") {
	 		if($url[0] != "/") $url = Director::baseURL()  . $url;
			$url = self::protocolAndHost() . $url;
		}

		return $url;
	}

	/**
	 * Returns the part of the URL, 'http://www.mysite.com'.
	 */
	static function protocolAndHost() {
		if(self::$alternateBaseURL) {
			if(preg_match('/^(http[^:]*:\/\/[^\/]+)\//', self::$alternateBaseURL, $matches)) {
				return $matches[1];
			}
		}

		$s = (isset($_SERVER['SSL']) || isset($_SERVER['HTTPS'])) ? 's' : '';
		return "http$s://" . $_SERVER['HTTP_HOST'];
	}


	/**
	 * Redirect to another page.
	 *  - $url can be an absolute URL
	 *  - or it can be a URL relative to the "site base"
	 *  - if it is just a word without an slashes, then it redirects to another action on the current controller.
	 */
	static function redirect($url, $code=302) {
		Controller::curr()->redirect($url, $code);
	}

	/**
	 * Tests whether a redirection has been requested.
	 * @return string If redirect() has been called, it will return the URL redirected to.  Otherwise, it will return null;
	 */
	static function redirected_to() {
		return Controller::curr()->redirectedTo();
	}
	
	/**
	 * Sets the HTTP status code
	 */
	static function set_status_code($code) {
		return Controller::curr()->getResponse()->setStatusCode($code);
	}
	
	/**
	 * Returns the current HTTP status code
	 */
	static function get_status_code() {
		return Controller::curr()->getResponse()->getStatusCode();
	}

	/*
	 * Redirect back
	 *
	 * Uses either the HTTP_REFERER or a manually set request-variable called
	 * _REDIRECT_BACK_URL.
	 * This variable is needed in scenarios where not HTTP-Referer is sent (
	 * e.g when calling a page by location.href in IE).
	 * If none of the two variables is available, it will redirect to the base
	 * URL (see {@link baseURL()}).
	 */
	static function redirectBack() {
		$url = self::baseURL();

		if(isset($_REQUEST['_REDIRECT_BACK_URL'])) {
			$url = $_REQUEST['_REDIRECT_BACK_URL'];
		} else if(isset($_SERVER['HTTP_REFERER'])) {
			$url = $_SERVER['HTTP_REFERER'];
		}

		Director::redirect($url);
	}

	/**
	 * @deprecated This seems like a bit of a hack; is it used anywhere?
	 */
	static function currentURLSegment() {
		return Director::$urlSegment;
	}

	/**
	 * Returns a URL to composed of the given segments - usually controller, action, parameter
	 * @deprecated This function has little value.  Just craft links yourself.
	 */
	static function link() {
		$parts = func_get_args();
		return Director::baseURL() . implode("/",$parts) . (sizeof($parts) > 2 ? "" : "/");
	}

	/**
	 * Returns the root URL for the site.
	 * It will be automatically calculated unless it is overridden with {@link setBaseURL()}.
	 */
	static function baseURL() {
		if(self::$alternateBaseURL) return self::$alternateBaseURL;
		else {
			$base = dirname(dirname($_SERVER['SCRIPT_NAME']));
			if($base == '/' || $base == '/.' || $base == '\\') return '/';
			else return $base . '/';
		}
	}
	
	/**
	 * Sets the root URL for the website.
	 * If the site isn't accessible from the URL you provide, weird things will happen.
	 */
	static function setBaseURL($baseURL) {
		self::$alternateBaseURL = $baseURL;
	}

	/**
	 * Returns the root filesystem folder for the site.
	 * It will be automatically calculated unless it is overridden with {@link setBaseFolder()}.
	 */
	static function baseFolder() {
		if(self::$alternateBaseFolder) return self::$alternateBaseFolder;
		else return dirname(dirname($_SERVER['SCRIPT_FILENAME']));
	}

	/**
	 * Sets the root folder for the website.
	 * If the site isn't accessible from the folder you provide, weird things will happen.
	 */
	static function setBaseFolder($baseFolder) {
		self::$alternateBaseFolder = $baseFolder;
	}

	/**
	 * Turns an absolute URL or folder into one that's relative to the root of the site.
	 * This is useful when turning a URL into a filesystem reference, or vice versa.
	 */
	static function makeRelative($url) {
		$base1 = self::absoluteBaseURL();
		$base2 = self::baseFolder();
		$base3 = self::baseURL();

		// Allow for the accidental inclusion of a // in the URL
		$url = ereg_replace('([^:])//','\\1/',$url);

		if(substr($url,0,strlen($base1)) == $base1) return substr($url,strlen($base1));
		else if(substr($url,0,strlen($base2)) == $base2) return substr($url,strlen($base2));
		else if(substr($url,0,strlen($base3)) == $base3) return substr($url,strlen($base3));
		
		return $url;
	}

	/**
	 * @deprecated This method's behaviour isn't very useful or consistent.
	 */
	static function getAbsURL($url) {
		return Director::baseURL() . $url;
	}

	/**
	 * Given a filesystem reference relative to the site root, return the full filesystem path
	 */
	/**
	 * Cleans up a given file-path
	 * 
	 * @param string $file
	 * @return string
	 */
	/**
	 * Cleans up a given file-path
	 * 
	 * @param string $file
	 * @return string
	 */
	static function getAbsFile($file) {
		if($file[0] == '/') return $file;
		return Director::baseFolder() . '/' . $file;
	}
	
	/**
	 * Returns true if the given file exists.
	 * @param $file Filename specified relative to the site root
	 */
	static function fileExists($file) {
		// replace any appended query-strings, e.g. /path/to/foo.php?bar=1 to /path/to/foo.php
		$file = preg_replace('/([^\?]*)?.*/','$1',$file);
		return file_exists(Director::getAbsFile($file));
	}

	/**
	 * Returns the Absolute URL of the site root.
	 */
	 static function absoluteBaseURL() {
	 	return Director::absoluteURL(Director::baseURL());
	 }
	 
	/**
	 * Returns the Absolute URL of the site root, embedding the current basic-auth credentials into the URL.
	 */
	 static function absoluteBaseURLWithAuth() {
	 	if($_SERVER['PHP_AUTH_USER'])
			$login = "$_SERVER[PHP_AUTH_USER]:$_SERVER[PHP_AUTH_PW]@";

	 	if($_SERVER['SSL']) $s = "s";
	 	return "http$s://" . $login .  $_SERVER['HTTP_HOST'] . Director::baseURL();
	 }

	/**
	 * Force the site to run on SSL.  To use, call from _config.php.
	 * 
	 * For example:
	 * <code>
	 * if(Director::isLive()) Director::forceSSL();
	 * </code>
	 */
	static function forceSSL() {
		if(!isset($_SERVER['HTTPS']) && !Director::isDev()){
			$destURL = str_replace('http:','https:',Director::absoluteURL($_SERVER['REQUEST_URI']));

			header("Location: $destURL");
			die("<h1>Your browser is not accepting header redirects</h1><p>Please <a href=\"$destURL\">click here</a>");
		}
	}

	/**
	 * Force a redirect to a domain starting with "www."
	 */
	static function forceWWW() {
		if(!Director::isDev() && !Director::isTest() && strpos( $_SERVER['SERVER_NAME'], 'www') !== 0 ){
			if( $_SERVER['HTTPS'] )
				$destURL = str_replace('https://','https://www.',Director::absoluteURL($_SERVER['REQUEST_URI']));
			else
				$destURL = str_replace('http://','http://www.',Director::absoluteURL($_SERVER['REQUEST_URI']));

			header("Location: $destURL");
			die("<h1>Your browser is not accepting header redirects</h1><p>Please <a href=\"$destURL\">click here</a>");
		}
	}

	/**
	 * Checks if the current HTTP-Request is an "Ajax-Request"
	 * by checking for a custom header set by prototype.js or
	 * wether a manually set request-parameter 'ajax' is present.
	 *
	 * @return boolean
	 */
	static function is_ajax() {
		if(Controller::has_curr()) {
			return Controller::curr()->isAjax();
		} else {
			return (
				isset($_REQUEST['ajax']) ||
				(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == "XMLHttpRequest")
			);
		}
	}
	
	/**
	 * Returns true if this script is being run from the command line rather than the webserver.
	 * 
	 * @return boolean
	 */
	public static function is_cli() {
		return preg_match('/cli-script\.php/', $_SERVER['SCRIPT_NAME']);
	}

	////////////////////////////////////////////////////////////////////////////////////////////
	// Site mode methods
	////////////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Sets the site mode (if it is the public site or the cms), and runs registered modules.
	 * 
	 * @param string $mode 'site' or 'cms' 
	 */
	static function set_site_mode($mode) {
		Director::$siteMode = $mode;
		
		if(isset(self::$callbacks[$mode])) {
			foreach(self::$callbacks[$mode] as $extension) {
				call_user_func($extension);
			}
		}
	}
	
	/**
	 * @return string 'site' or 'cms'
	 */
	static function get_site_mode() {
		return Director::$siteMode;
	}

	/**
	 * Allows a module to register with the director to be run once
	 * the controller is instantiated.  The optional 'mode' parameter
	 * can be either 'site' or 'cms', as those are the two values currently
	 * set by controllers.  The callback function will be run at the
	 * initialization of the relevant controller.
	 * 
	 * @param $function string PHP-function array based on http://php.net/call_user_func
	 * @param $mode string
	 */
	static function add_callback($function, $mode = 'site') {
		self::$callbacks[$mode][] = $function;
	}

	////////////////////////////////////////////////////////////////////////////////////////////
	// Environment type methods
	////////////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Set the environment type of the current site.
	 *
	 * Typically, a SilverStripe site have a number of environments: 
	 *  - development environments, such a copy on your local machine.
	 *  - test sites, such as the one you show the client before going live.
	 *  - the live site itself.
	 * 
	 * The behaviour of these environments often varies slightly.  For example, development sites may have errors dumped to the screen,
	 * and order confirmation emails might be sent to the developer instead of the client.
	 * 
	 * To help with this, Sapphire support the notion of an environment type.  The environment type can be dev, test, or live.
	 * 
	 * You can set it explicitly with Director::set_environment_tpye().  Or you can use {@link Director::set_dev_servers()} and {@link Director::set_test_servers()}
	 * to set it implicitly, based on the value of $_SERVER['HTTP_HOST'].  If the HTTP_HOST value is one of the servers listed, then
	 * the environment type will be test or dev.  Otherwise, the environment type will be live.
	 *
	 * Dev mode can also be forced by putting ?isDev=1 in your URL, which will ask you to log in and then push the site into dev
	 * mode for the remainder of the session. Putting ?isDev=0 onto the URL can turn it back.
	 * Generally speaking, these methods will be called from your _config.php file.
	 * 
	 * Once the environment type is set, it can be checked with {@link Director::isDev()}, {@link Director::isTest()}, and
	 * {@link Director::isLive()}.
	 * 
	 * @param $et string The environment type: dev, test, or live.
	 */
	static function set_environment_type($et) {
		if($et != 'dev' && $et != 'test' && $et != 'live') {
			user_error("Director::set_environment_type passed '$et'.  It should be passed dev, test, or live");
		} else {
			self::$environment_type = $et;
		}
	}

	/**
	 * Specify HTTP_HOST values that are development environments.
	 * For information about environment types, see {@link Director::set_environment_type()}.
	 * @param $servers array An array of HTTP_HOST values that should be treated as development environments.
	 */
	static function set_dev_servers($servers) {
		Director::$dev_servers = $servers;
	}
	
	/**
	 * Specify HTTP_HOST values that are test environments.
	 * For information about environment types, see {@link Director::set_environment_type()}.
	 * @param $servers array An array of HTTP_HOST values that should be treated as test environments.
	 */
	static function set_test_servers($servers) {
		Director::$test_servers = $servers;
	}

	/*
	 * This function will return true if the site is in a live environment.
	 * For information about environment types, see {@link Director::set_environment_type()}.
	 */
	static function isLive() {
		return !(Director::isDev() || Director::isTest());
	}
	
	/**
	 * This function will return true if the site is in a development environment.
	 * For information about environment types, see {@link Director::set_environment_type()}.
	 */
	static function isDev() {
		if(self::$environment_type) return self::$environment_type == 'dev';

		// Use ?isDev=1 to get development access on the live server
		if(isset($_GET['isDev'])) {
			if(ClassInfo::ready()) {
				BasicAuth::requireLogin("SilverStripe developer access.  Use your  CMS login", "ADMIN");
				$_SESSION['isDev'] = $_GET['isDev'];
			} else {
				return true;
			}
		}
		
		if(isset($_SESSION['isDev']) && $_SESSION['isDev']) return true;
		
		// Check if we are running on one of the development servers
		if(in_array($_SERVER['HTTP_HOST'], Director::$dev_servers))  {
			return true;
		}
		/*
		// Check if we are running on one of the test servers
		if(in_array($_SERVER['HTTP_HOST'], Director::$test_servers))  {
			return true;
		}
		*/
		
		return false;
	}
	
	/**
	 * This function will return true if the site is in a test environment.
	 * For information about environment types, see {@link Director::set_environment_type()}.
	 */
	static function isTest() {
		if(self::$environment_type) {
			return self::$environment_type == 'test';
		}
		
		// Check if we are running on one of the test servers
		if(in_array($_SERVER['HTTP_HOST'], Director::$test_servers))  {
			return true;
		}
		
		return false;
	}

	/**
	 * @deprecated use isDev() instead
	 */
	function isDevMode() {
		user_error('Director::isDevMode() is deprecated. Use Director::isDev() instead.', E_USER_NOTICE);
		return self::isDev();
	}
	
	/**
	 * @deprecated use isTest() instead
	 */
	function isTestMode() {
		user_error('Director::isTestMode() is deprecated. Use Director::isTest() instead.', E_USER_NOTICE);
		return self::isTest();
	}
	
	/**
	 * @deprecated use isLive() instead
	 */
	function isLiveMode() {
		user_error('Director::isLiveMode() is deprecated. Use Director::isLive() instead.', E_USER_NOTICE);
		return self::isLive();
	}

}

?>
