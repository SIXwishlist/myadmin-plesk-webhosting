<?php

namespace Detain\MyAdminPlesk;

use Detain\MyAdminPlesk\ApiRequestException;
use Detain\MyAdminPlesk\Plesk;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Class Plugin
 *
 * @package Detain\MyAdminPlesk
 */
class Plugin {

	public static $name = 'Plesk Webhosting';
	public static $description = 'Single control panel with an intuitive graphical interface, ready-to-code environment and powerful extensions. Everything you need to develop websites and apps that scale in the cloud.  More info at https://www.plesk.com/';
	public static $help = '';
	public static $module = 'webhosting';
	public static $type = 'service';

	/**
	 * Plugin constructor.
	 */
	public function __construct() {
	}

	/**
	 * @return array
	 */
	public static function getHooks() {
		return [
			self::$module.'.settings' => [__CLASS__, 'getSettings'],
			self::$module.'.activate' => [__CLASS__, 'getActivate'],
			self::$module.'.reactivate' => [__CLASS__, 'getReactivate'],
			self::$module.'.deactivate' => [__CLASS__, 'getDeactivate'],
			self::$module.'.terminate' => [__CLASS__, 'getTerminate'],
			'function.requirements' => [__CLASS__, 'getRequirements']
		];
	}

	/**
	 * @param \Symfony\Component\EventDispatcher\GenericEvent $event
	 * @throws \Detain\MyAdminPlesk\ApiRequestException
	 * @throws \Detain\MyAdminPlesk\Detain\MyAdminPlesk\ApiRequestException
	 */
	public static function getActivate(GenericEvent $event) {
		if ($event['category'] == get_service_define('WEB_PLESK')) {
			myadmin_log(self::$module, 'info', 'Plesk Activation', __LINE__, __FILE__);
			$serviceClass = $event->getSubject();
			$settings = get_module_settings(self::$module);
			$serverdata = get_service_master($serviceClass->getServer(), self::$module);
			$ip = $serverdata[$settings['PREFIX'].'_ip'];
			$extra = run_event('parse_service_extra', $serviceClass->getExtra(), self::$module);
			$hostname = $serviceClass->getHostname();
			if (trim($hostname) == '')
				$hostname = $serviceClass->getId().'.server.com';
			$password = website_get_password($serviceClass->getId());
			$username = get_new_webhosting_username($serviceClass->getId(), $hostname, $serviceClass->getServer());
			$data = $GLOBALS['tf']->accounts->read($serviceClass->getCustid());
			$debugCalls = FALSE;
			if (!is_array($extra))
				$extra = [];
			function_requirements('get_webhosting_plesk_instance');
			$plesk = get_webhosting_plesk_instance($serverdata);
			/**
			 * Gets the Shared IP Address
			 */
			$result = $plesk->listIpAddresses();
			if ((!isset($result['ips'][0]['ip_address']) && !isset($result['ips']['ip_address'])) || $result['status'] == 'error')
				throw new Exception('Failed getting server information.'.(isset($result['errtext']) ? ' Error message was: '.$result['errtext'].'.' : ''));
			if ($debugCalls == TRUE)
				echo 'plesk->list_ip_adddresses() = '.var_export($result, TRUE).PHP_EOL;
			if (isset($result['ips']['ip_address']))
				$sharedIp = $result['ips']['ip_address'];
			else
				foreach ($result['ips'] as $idx => $ipData)
					if (trim($ipData['type']) == 'shared' && (!isset($sharedIp) || $ipData['is_default']))
						$sharedIp = $ipData['ip_address'];
			if (!isset($sharedIp)) {
				myadmin_log(self::$module, 'critical', 'Plesk Could not find any shared IP addresses', __LINE__, __FILE__);
				$event['success'] = FALSE;
				$event->stopPropagation();
				return;
			}
			/**
			 * Gets the Service Plans and finds the one matching the desired parameters
			 */
			try {
				$result = $plesk->listServicePlans();
			} catch (ApiRequestException $e) {
				myadmin_log(self::$module, 'info', 'listServicePlans Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
				$event['success'] = FALSE;
				$event->stopPropagation();
				return;
			}
			if ($debugCalls == TRUE)
				echo 'plesk->listServicePlans() = '.var_export($result, TRUE).PHP_EOL;
			foreach ($result as $idx => $plan) {
				if ($plan['name'] == 'ASP.NET plan') {
					$planId = $plan['id'];
					break;
				}
			}
			if (!isset($planId)) {
				myadmin_log(self::$module, 'critical', 'Plesk Could not find the appropriate service plan');
				$event['success'] = FALSE;
				$event->stopPropagation();
				return;
			}
			/**
			 * Creates a Client in with Plesk
			 */
			if (!isset($data['name']) || trim($data['name']) == '') {
				$data['name'] = str_replace('@', ' ', $data['account_lid']);
			}
			$request = [
				'name' => $data['name'],
				'username' => $username,
				'password' => $password
			];
			try {
				myadmin_log(self::$module, 'DEBUG', 'createClient called with '.json_encode($request), __LINE__, __FILE__);
				$result = $plesk->createClient($request);
			} catch (ApiRequestException $e) {
				$error = $e->getMessage();
				myadmin_log(self::$module, 'info', 'createClient Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
			}
			if (!isset($result['id'])) {
				$cantFix = FALSE;
				$passwordUpdated = FALSE;
				while ($cantFix == FALSE && !isset($result['id'])) {
					if (mb_strpos($error, 'The password should') !== FALSE) {
						// Error #2204 System user setting was failed. Error: The password should be  4 - 255 characters long and should not contain the username. Do not use quotes, spaces, and national alphabetic characters in the password.
						$passwordUpdated = TRUE;
						$password = Plesk::randomString(16);
						$request['password'] = $password;
						myadmin_log(self::$module, 'info', "Generated '{$request['password']}' for a replacement password and trying again", __LINE__, __FILE__);
						try {
							myadmin_log(self::$module, 'DEBUG', 'createClient called with '.json_encode($request), __LINE__, __FILE__);
							$result = $plesk->createClient($request);
						} catch (ApiRequestException $e) {
							$error = $e->getMessage();
							myadmin_log(self::$module, 'info', 'createClient Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
						}
					} elseif (mb_strpos($error, 'Error #1007') !== FALSE) {
						// Error #1007 User account  already exists.
						$usernameUpdated = TRUE;
						$username = mb_substr($username, 0, 7).strtolower(Plesk::randomString(1));
						$request['username'] = $username;
						myadmin_log(self::$module, 'info', "Generated '{$request['username']}' for a replacement username and trying again", __LINE__, __FILE__);
						try {
							myadmin_log(self::$module, 'DEBUG', 'createClient called with '.json_encode($request), __LINE__, __FILE__);
							$result = $plesk->createClient($request);
						} catch (ApiRequestException $e) {
							$error = $e->getMessage();
							myadmin_log(self::$module, 'info', 'createClient Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
						}
					} else
						$cantFix = TRUE;
				}
				if ($passwordUpdated == TRUE)
					$GLOBALS['tf']->history->add($settings['PREFIX'], 'password', $serviceClass->getId(), $request['password']);
			}
			request_log(self::$module, $serviceClass->getCustid(), __FUNCTION__, 'plesk', 'createClient', $request, $result);
			if (!isset($result['id'])) {
				//myadmin_log(self::$module, 'info', 'createClient did not return the expected id information: '.$e->getMessage(), __LINE__, __FILE__);
				myadmin_log(self::$module, 'info', 'createClient did not return the expected id', __LINE__, __FILE__);
				if (is_array($extra) && isset($extra[0]) && is_numeric($extra[0]) && $extra[0] > 0) {
					myadmin_log(self::$module, 'info', 'continuing using pre-existing client id', __LINE__, __FILE__);
					$accountId = $extra[0];
				} else {
					$event['success'] = FALSE;
					$event->stopPropagation();
					return;
				}
			} else {
				$accountId = $result['id'];
			}
			//$ftpLogin = 'ftpuser'.$serviceClass->getId();
			//$ftpLogin = 'ftp'.Plesk::randomString(9);
			//$ftpLogin = 'ftp'.str_replace('.',''), array('',''), $hostname);
			//$ftpPassword = Plesk::randomString(16);
			$ftpPassword = generateRandomString(10, 2, 1, 1, 1);
			while (mb_strpos($ftpPassword, '&') !== FALSE)
				$ftpPassword = generateRandomString(10, 2, 1, 1, 1);
			$extra[0] = $accountId;
			$db = get_module_db(self::$module);
			$serExtra = $db->real_escape(myadmin_stringify($extra));
			$db->query("update {$settings['TABLE']} set {$settings['PREFIX']}_ip='{$ip}', {$settings['PREFIX']}_extra='{$serExtra}' where {$settings['PREFIX']}_id='{$serviceClass->getId()}'", __LINE__, __FILE__);
			myadmin_log(self::$module, 'info', "createClient got client id {$accountId}", __LINE__, __FILE__);
			//$plesk->debug = TRUE;
			//$debugCalls = TRUE;
			$request = [
				'domain' => $hostname,
				'owner_id' => $accountId,
				'htype' => 'vrt_hst',
				'ftp_login' => $username,
				'ftp_password' => $ftpPassword,
				'ip' => $ip,
				'status' => 0,
				'plan_id' => $planId
			];
			$result = [];
			try {
				myadmin_log(self::$module, 'info', 'createSubscription called with '.json_encode($request), __LINE__, __FILE__);
				$result = $plesk->createSubscription($request);
			} catch (ApiRequestException $e) {
				myadmin_log(self::$module, 'warning', ' createSubscription Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
				try {
					myadmin_log(self::$module, 'info', 'deleteClient called with '.json_encode($request), __LINE__, __FILE__);
					$result = $plesk->deleteClient(['login' => $username]);
				} catch (ApiRequestException $e) {
					$error = $e->getMessage();
					myadmin_log(self::$module, 'warning', 'deleteClient Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
				}
				$event['success'] = FALSE;
				$event->stopPropagation();
				return;
			}

			if (!isset($result['id'])) {
				$cantFix = FALSE;
				$usernameUpdated = FALSE;
				while ($cantFix == FALSE && !isset($result['id'])) {
					// Error #1007 User account  already exists.
					if (mb_strpos($error, 'Error #1007') !== FALSE) {
						$usernameUpdated = TRUE;
						$username = mb_substr($username, 0, 7).strtolower(Plesk::randomString(1));
						$request['ftp_login'] = $username;
						myadmin_log(self::$module, 'info', "Generated '{$request['ftp_login']}' for a replacement username and trying again", __LINE__, __FILE__);
						try {
							myadmin_log(self::$module, 'DEBUG', 'createSubscription called with '.json_encode($request), __LINE__, __FILE__);
							$result = $plesk->createSubscription($request);
						} catch (ApiRequestException $e) {
							$error = $e->getMessage();
							myadmin_log(self::$module, 'info', 'createClient Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
						}
					} else
						$cantFix = TRUE;
				}
			}
			request_log(self::$module, $serviceClass->getCustid(), __FUNCTION__, 'plesk', 'createSubscription', $request, $result);
			if (!isset($result['id'])) {
				myadmin_log(self::$module, 'info', 'createSubscription did not return the expected id information: '.$e->getMessage(), __LINE__, __FILE__);
				$event['success'] = FALSE;
				$event->stopPropagation();
				return;
			}
			$subscriptoinId = $result['id'];
			$extra[1] = $subscriptoinId;
			$serExtra = $db->real_escape(myadmin_stringify($extra));
			$db->query("update {$settings['TABLE']} set {$settings['PREFIX']}_ip='{$ip}', {$settings['PREFIX']}_extra='{$serExtra}', {$settings['PREFIX']}_username='{$username}' where {$settings['PREFIX']}_id='{$serviceClass->getId()}'", __LINE__, __FILE__);
			if ($debugCalls == TRUE)
				echo 'plesk->createSubscription('.var_export($request, TRUE).') = '.var_export($result, TRUE).PHP_EOL;
			myadmin_log(self::$module, 'info', "createSubscription got Subscription ID {$subscriptoinId}\n", __LINE__, __FILE__);
			if (is_numeric($subscriptoinId)) {
				website_welcome_email($serviceClass->getId());
			}
			$event->stopPropagation();
		}
	}

	/**
	 * @param \Symfony\Component\EventDispatcher\GenericEvent $event
	 * @throws \Detain\MyAdminPlesk\Detain\MyAdminPlesk\ApiRequestException
	 */
	public static function getReactivate(GenericEvent $event) {
		if ($event['category'] == get_service_define('WEB_PLESK')) {
			$serviceClass = $event->getSubject();
			$serverdata = get_service_master($serviceClass->getServer(), self::$module);
			function_requirements('get_webhosting_plesk_instance');
			$plesk = get_webhosting_plesk_instance($serverdata);
			$request = ['username' => $serviceClass->getUsername(), 'status' => 0];
			try {
				$result = $plesk->updateClient($request);
			} catch (ApiRequestException $e) {
				echo 'Caught exception: '.$e->getMessage().PHP_EOL;
			}
			myadmin_log(self::$module, 'info', 'updateClient Called got '.json_encode($result), __LINE__, __FILE__);
			$event->stopPropagation();
		}
	}

	/**
	 * @param \Symfony\Component\EventDispatcher\GenericEvent $event
	 * @throws \Detain\MyAdminPlesk\Detain\MyAdminPlesk\ApiRequestException
	 */
	public static function getDeactivate(GenericEvent $event) {
		if ($event['category'] == get_service_define('WEB_PLESK')) {
			myadmin_log(self::$module, 'info', 'Plesk Deactivation', __LINE__, __FILE__);
			$serviceClass = $event->getSubject();
			$serverdata = get_service_master($serviceClass->getServer(), self::$module);
			function_requirements('get_webhosting_plesk_instance');
			$plesk = get_webhosting_plesk_instance($serverdata);
			$request = ['username' => $serviceClass->getUsername(), 'status' => 1];
			try {
				$result = $plesk->updateClient($request);
				myadmin_log(self::$module, 'info', 'updateClient('.json_encode($request).') Called got '.json_encode($result), __LINE__, __FILE__);
			} catch (ApiRequestException $e) {
				myadmin_log(self::$module, 'info', 'updateClient('.json_encode($request).') Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
			}
			$event->stopPropagation();
		}
	}

	/**
	 * @param \Symfony\Component\EventDispatcher\GenericEvent $event
	 * @return boolean|null
	 * @throws \Detain\MyAdminPlesk\ApiRequestException
	 * @throws \Detain\MyAdminPlesk\Detain\MyAdminPlesk\ApiRequestException
	 */
	public static function getTerminate(GenericEvent $event) {
		if ($event['category'] == get_service_define('WEB_PLESK')) {
			$event->stopPropagation();
			myadmin_log(self::$module, 'info', 'Plesk Termination', __LINE__, __FILE__);
			$serviceClass = $event->getSubject();
			$extra = run_event('parse_service_extra', $serviceClass->getExtra(), self::$module);
			$serverdata = get_service_master($serviceClass->getServer(), self::$module);
			function_requirements('get_webhosting_plesk_instance');
			$plesk = get_webhosting_plesk_instance($serverdata);
			if (!isset($extra[1]))
				return FALSE;
			list($userId, $subscriptoinId) = $extra;
			/*
			$request = array('id' => $data['site_id']);
			try {
				$result = $plesk->deleteSite($request);
			} catch (Exception $e) {
				billingd_log('deleteSite Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
				echo 'Caught exception: '.$e->getMessage() . "\n";
			}
			myadmin_log(self::$module, 'info', 'deleteSite Called got '.json_encode($result), __LINE__, __FILE__);
			*/
			$request = ['id' => $subscriptoinId];
			try {
				$result = $plesk->deleteSubscription($request);
			} catch (Exception $e) {
				billingd_log('deleteSubscription id:'.$subscriptoinId.' Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
				echo 'Caught exception: '.$e->getMessage()."\n";
			}
			myadmin_log(self::$module, 'info', 'deleteSubscription Called got '.json_encode($result), __LINE__, __FILE__);
			$request = ['id' => $userId];
			try {
				$result = $plesk->deleteClient($request);
			} catch (Exception $e) {
				billingd_log('deleteClient id:'.$userId.' Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
				echo 'Caught exception: '.$e->getMessage()."\n";
			}
			myadmin_log(self::$module, 'info', 'deleteClient Called got '.json_encode($result), __LINE__, __FILE__);
			return TRUE;
		}
	}

	/**
	 * @param \Symfony\Component\EventDispatcher\GenericEvent $event
	 */
	public static function getChangeIp(GenericEvent $event) {
		if ($event['category'] == get_service_define('WEB_PLESK')) {
			$serviceClass = $event->getSubject();
			$settings = get_module_settings(self::$module);
			$plesk = new Plesk(FANTASTICO_USERNAME, FANTASTICO_PASSWORD);
			myadmin_log(self::$module, 'info', 'IP Change - (OLD:'.$serviceClass->getIp().") (NEW:{$event['newip']})", __LINE__, __FILE__);
			$result = $plesk->editIp($serviceClass->getIp(), $event['newip']);
			if (isset($result['faultcode'])) {
				myadmin_log(self::$module, 'error', 'Plesk editIp('.$serviceClass->getIp().', '.$event['newip'].') returned Fault '.$result['faultcode'].': '.$result['fault'], __LINE__, __FILE__);
				$event['status'] = 'error';
				$event['status_text'] = 'Error Code '.$result['faultcode'].': '.$result['fault'];
			} else {
				$GLOBALS['tf']->history->add($settings['TABLE'], 'change_ip', $event['newip'], $serviceClass->getIp());
				$serviceClass->set_ip($event['newip'])->save();
				$event['status'] = 'ok';
				$event['status_text'] = 'The IP Address has been changed.';
			}
			$event->stopPropagation();
		}
	}

	/**
	 * @param \Symfony\Component\EventDispatcher\GenericEvent $event
	 */
	public static function getMenu(GenericEvent $event) {
		$menu = $event->getSubject();
		if ($GLOBALS['tf']->ima == 'admin') {
			$menu->add_link(self::$module, 'choice=none.reusable_plesk', 'images/icons/database_warning_48.png', 'ReUsable Plesk Licenses');
			$menu->add_link(self::$module, 'choice=none.plesk_list', 'images/icons/database_warning_48.png', 'Plesk Licenses Breakdown');
			$menu->add_link(self::$module.'api', 'choice=none.plesk_licenses_list', 'whm/createacct.gif', 'List all Plesk Licenses');
		}
	}

	/**
	 * @param \Symfony\Component\EventDispatcher\GenericEvent $event
	 */
	public static function getRequirements(GenericEvent $event) {
		$loader = $event->getSubject();
		$loader->add_requirement('get_webhosting_plesk_instance', '/../vendor/detain/myadmin-plesk-webhosting/src/get_webhosting_plesk_instance.php');
	}

	/**
	 * @param \Symfony\Component\EventDispatcher\GenericEvent $event
	 */
	public static function getSettings(GenericEvent $event) {
		$settings = $event->getSubject();
		$settings->add_select_master(self::$module, 'Default Servers', self::$module, 'new_website_plesk_server', 'Default Plesk Setup Server', NEW_WEBSITE_PLESK_SERVER, get_service_define('WEB_PLESK'));
		$settings->add_dropdown_setting(self::$module, 'Out of Stock', 'outofstock_webhosting_plesk', 'Out Of Stock Plesk Webhosting', 'Enable/Disable Sales Of This Type', $settings->get_setting('OUTOFSTOCK_WEBHOSTING_PLESK'), ['0', '1'], ['No', 'Yes']);
	}

}
