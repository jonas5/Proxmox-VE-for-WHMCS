<?php

/*  
	Proxmox VE for WHMCS - Addon/Server Modules for WHMCS (& PVE)
	https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/
	File: /modules/servers/pvewhmcs/pvewhmcs.php (PVE Work)

	Copyright (C) The Network Crew Pty Ltd (TNC) & Co.

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>. 
*/

// DEP: Proxmox API Class - make sure we can access via PVE via API
if (file_exists('../modules/addons/pvewhmcs/proxmox.php'))
	require_once('../modules/addons/pvewhmcs/proxmox.php');
else
	require_once(ROOTDIR . '/modules/addons/pvewhmcs/proxmox.php');

// Import SQL Connectivity (WHMCS)
use Illuminate\Database\Capsule\Manager as Capsule;

// Prepare to source Guest type
global $guest;

// WHMCS CONFIG > SERVICES/PRODUCTS > Their Service > Tab #3 (Plan/Pool)
function pvewhmcs_ConfigOptions() {
	// Retrieve PVE for WHMCS Cluster
	$server=Capsule::table('tblservers')->where('type', '=', 'pvewhmcs')->get()[0] ;

	// Retrieve Plans
	foreach (Capsule::table('mod_pvewhmcs_plans')->get() as $plan) {
		$plans[$plan->id]=$plan->vmtype.'&nbsp;:&nbsp;'.$plan->title ;
	}

	// Retrieve IP Pools
	foreach (Capsule::table('mod_pvewhmcs_ip_pools')->get() as $ippool) {
		$ippools[$ippool->id]=$ippool->title ;
	}
	
	/*
	$proxmox = new PVE2_API($server->ipaddress, $server->username, "pam", get_server_pass_from_whmcs($server->password));
	if ($proxmox->login()) {
		# Get first node name.
		$nodes = $proxmox->get_node_list();
		$first_node = $nodes[0];
		unset($nodes);

		$storage_contents = $proxmox->get('/nodes/'.$first_node.'/storage/local/content') ;

		foreach ($storage_contents as $storage_content) {
			if ($storage_content['content']=='vztmpl') {
				$templates[$storage_content['volid']]=explode('.',explode('/',$storage_content['volid'])[1])[0] ;
			}
		}
	}
	*/
	
	// OPTIONS FOR THE QEMU/LXC PACKAGE; ties WHMCS PRODUCT to MODULE PLAN/POOL
	// Ref: https://developers.whmcs.com/provisioning-modules/config-options/
	// SQL/Param: configoption1 configoption2
	$configarray = array(
		"Plan" => array(
			"FriendlyName" => "PVE Plan",
			"Type" => "dropdown",
			'Options' => $plans ,
			"Description" => "QEMU/LXC : Plan Name"
		),
		"IPPool" => array(
			"FriendlyName" => "IPv4 Pool",
			"Type" => "dropdown",
			'Options'=> $ippools,
			"Description" => "IPv4 : Allocation Pool"
		),
	);

	// Deliver the options back into WHMCS
	return $configarray;
}

// PVE API FUNCTION: Create the Service on the Hypervisor
function pvewhmcs_CreateAccount($params) {
	// Make sure "WHMCS Admin > Products/Services > Proxmox-based Service -> Plan + Pool" are set. Else, fail early. (Issue #36)
	if (!isset($params['configoption1'], $params['configoption2'])) {
		throw new Exception("PVEWHMCS Error: Missing Config. Service/Product WHMCS Config not saved (Plan/Pool not assigned to WHMCS Service type). Check Support/Health tab in Module Config for info. Quick and easy fix.");
	}
	if (empty($params['configoption1'])) {
		throw new Exception("PVEWHMCS Error: Missing Config. Service/Product WHMCS Config not saved (Plan/Pool not assigned to WHMCS Service type). Check Support/Health tab in Module Config for info. Quick and easy fix.");
	}
	if (empty($params['configoption2'])) {
		throw new Exception("PVEWHMCS Error: Missing Config. Service/Product WHMCS Config not saved (Plan/Pool not assigned to WHMCS Service type). Check Support/Health tab in Module Config for info. Quick and easy fix.");
	}

	// Retrieve Plan from table
	$plan = Capsule::table('mod_pvewhmcs_plans')->where('id', '=', $params['configoption1'])->get()[0];

	// PVE Host - Connection Info
	$serverip = $params["serverip"];
	$serverusername = $params["serverusername"];
	$serverpassword = $params["serverpassword"];

	// Prepare the service config array
	$vm_settings = array();

	// Select an IP Address from Pool
	$ip = Capsule::select('select ipaddress,mask,gateway from mod_pvewhmcs_ip_addresses i INNER JOIN mod_pvewhmcs_ip_pools p on (i.pool_id=p.id and p.id=' . $params['configoption2'] . ') where  i.ipaddress not in(select ipaddress from mod_pvewhmcs_vms) limit 1')[0];

	////////////////////////
	// CREATE IF QEMU/KVM //
	////////////////////////
	if (!empty($params['customfields']['KVMTemplate'])) {
		// KVM TEMPLATE - CREATION LOGIC
		$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword);
		if ($proxmox->login()) {
			// Get first node name.
			$nodes = $proxmox->get_node_list();
			$first_node = $nodes[0];
			unset($nodes);
			$vm_settings['newid'] = $params["serviceid"];
			$vm_settings['name'] = "vps" . $params["serviceid"] . "-cus" . $params['clientsdetails']['userid'];
			$vm_settings['full'] = true;
			// KVM TEMPLATE - Conduct the VM CLONE from Template to Machine
			$logrequest = '/nodes/' . $first_node . '/qemu/' . $params['customfields']['KVMTemplate'] . '/clone' . $vm_settings;
			$response = $proxmox->post('/nodes/' . $first_node . '/qemu/' . $params['customfields']['KVMTemplate'] . '/clone', $vm_settings);

			// DEBUG - Log the request parameters before it's fired
			if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
				logModuleCall(
					'pvewhmcs',
					__FUNCTION__,
					$logrequest,
					json_decode($response)
				);
			}

			// Extract UPID from the response (Proxmox returns colon-delimited string)
			if (strpos($response, 'UPID:') === 0) {
				$upid = trim($response); // Extract the entire UPID including "UPID:"

				// Poll for task completion
				$max_retries = 10;  // Total retries (avoid infinite loop)
				$retry_interval = 15;  // Delay in seconds between retries
				$completed = false;  // Starting - not complete until done

				for ($i = 0; $i < $max_retries; $i++) {
					// Check task status
					$task_status = $proxmox->get('/nodes/' . $first_node . '/tasks/' . $upid . '/status');

					if (isset($task_status['status']) && $task_status['status'] === 'stopped') {
						// Task is completed, now check exit status
						if (isset($task_status['exitstatus']) && $task_status['exitstatus'] === 'OK') {
							$completed = true;
							break;
						} else {
							// Task stopped, but failed with an exit status
							throw new Exception("Proxmox Error: Task failed with exit status: " . $task_status['exitstatus']);
						}
					} elseif ($task_status['status'] === 'running') {
						// Task is still running, wait and retry
						sleep($retry_interval);
					} else {
						// Unexpected task status
						throw new Exception("Proxmox Error: Unexpected task status: " . json_encode($task_status));
					}
				}

				if (!$completed) {
					throw new Exception("Proxmox Error: Task did not complete in time. Adjust ~/modules/servers/pvewhmcs/pvewhmcs.php >> max_retries option (2 locations).");
				}

				// Task is completed, now update the database with VM details.
				Capsule::table('mod_pvewhmcs_vms')->insert(
					[
						'id' => $params['serviceid'],
						'user_id' => $params['clientsdetails']['userid'],
						'vtype' => 'qemu',
						'ipaddress' => $ip->ipaddress,
						'subnetmask' => $ip->mask,
						'gateway' => $ip->gateway,
						'created' => date("Y-m-d H:i:s"),
						'v6prefix' => $plan->ipv6,
					]
				);
				// ISSUE #32 relates - amend post-clone to ensure excludes-disk amendments are all done, too.
				$cloned_tweaks['memory'] = $plan->memory;
				$cloned_tweaks['ostype'] = $plan->ostype;
				$cloned_tweaks['sockets'] = $plan->cpus;
				$cloned_tweaks['cores'] = $plan->cores;
				$cloned_tweaks['cpu'] = $plan->cpuemu;
				$cloned_tweaks['kvm'] = $plan->kvm;
				$cloned_tweaks['onboot'] = $plan->onboot;
				$amendment = $proxmox->post('/nodes/' . $first_node . '/qemu/' . $vm_settings['newid'] . '/config', $cloned_tweaks);
				return true;
			} else {
				throw new Exception("Proxmox Error: Failed to initiate clone. Response: " . json_encode($response));
			}
		} else {
			throw new Exception("Proxmox Error: PVE API login failed. Please check your credentials.");
		}
		/////////////////////////////////////////////////
		// PREPARE SETTINGS FOR QEMU/LXC EVENTUALITIES //
		/////////////////////////////////////////////////
	} else {
		$vm_settings['vmid'] = $params["serviceid"];
		if ($plan->vmtype == 'lxc') {
			///////////////////////////
			// LXC: Preparation Work //
			///////////////////////////
			$vm_settings['ostemplate'] = $params['customfields']['Template'];
			$vm_settings['swap'] = $plan->swap;
			$vm_settings['rootfs'] = $plan->storage . ':' . $plan->disk;
			$vm_settings['bwlimit'] = $plan->diskio;
			$vm_settings['nameserver'] = '1.1.1.1 1.0.0.1';
			$vm_settings['net0'] = 'name=eth0,bridge=' . $plan->bridge . $plan->vmbr . ',ip=' . $ip->ipaddress . '/' . mask2cidr($ip->mask) . ',gw=' . $ip->gateway . ',rate=' . $plan->netrate;
			if (!empty($plan->ipv6) && $plan->ipv6 != '0') {
				// Standard prep for the 2nd int.
				$vm_settings['net1'] = 'name=eth1,bridge=' . $plan->bridge . $plan->vmbr . ',rate=' . $plan->netrate;
				switch ($plan->ipv6) {
					case 'auto':
						// Pass in auto, triggering SLAAC
						$vm_settings['nameserver'] .= ' 2606:4700:4700::1111 2606:4700:4700::1001';
						$vm_settings['net1'] .= ',ip6=auto';
						break;
					case 'dhcp':
						// DHCP for IPv6 option
						$vm_settings['nameserver'] .= ' 2606:4700:4700::1111 2606:4700:4700::1001';
						$vm_settings['net1'] .= ',ip6=dhcp';
						break;
					case 'prefix':
						// Future development
						break;
					default:
						break;
				}
				if (!empty($plan->vlanid)) {
					$vm_settings['net1'] .= ',trunk=' . $plan->vlanid;
				}
			}
			if (!empty($plan->vlanid)) {
				$vm_settings['net0'] .= ',trunk=' . $plan->vlanid;
			}
			$vm_settings['onboot'] = $plan->onboot;
			$vm_settings['password'] = $params['customfields']['Password'];
		} else {
			////////////////////////////
			// QEMU: Preparation Work //
			////////////////////////////
			$vm_settings['ostype'] = $plan->ostype;
			$vm_settings['sockets'] = $plan->cpus;
			$vm_settings['cores'] = $plan->cores;
			$vm_settings['cpu'] = $plan->cpuemu;
			$vm_settings['nameserver'] = '1.1.1.1 1.0.0.1';
			$vm_settings['ipconfig0'] = 'ip=' . $ip->ipaddress . '/' . mask2cidr($ip->mask) . ',gw=' . $ip->gateway;
			if (!empty($plan->ipv6) && $plan->ipv6 != '0') {
				switch ($plan->ipv6) {
					case 'auto':
						// Pass in auto, triggering SLAAC
						$vm_settings['nameserver'] .= ' 2606:4700:4700::1111 2606:4700:4700::1001';
						$vm_settings['ipconfig1'] = 'ip6=auto';
						break;
					case 'dhcp':
						// DHCP for IPv6 option
						$vm_settings['nameserver'] .= ' 2606:4700:4700::1111 2606:4700:4700::1001';
						$vm_settings['ipconfig1'] = 'ip6=dhcp';
						break;
					case 'prefix':
						// Future development
						break;
					default:
						break;
				}
			}
			$vm_settings['kvm'] = $plan->kvm;
			$vm_settings['onboot'] = $plan->onboot;

			$vm_settings[$plan->disktype . '0'] = $plan->storage . ':' . $plan->disk . ',format=' . $plan->diskformat;
			if (!empty($plan->diskcache)) {
				$vm_settings[$plan->disktype . '0'] .= ',cache=' . $plan->diskcache;
			}
			$vm_settings['bwlimit'] = $plan->diskio;

			// ISO: Attach file to the guest
			if (isset($params['customfields']['ISO'])) {
				$vm_settings['ide2'] = 'local:iso/' . $params['customfields']['ISO'] . ',media=cdrom';
			}

			// NET: Config specifics for guest networking
			if ($plan->netmode != 'none') {
				$vm_settings['net0'] = $plan->netmodel;
				if ($plan->netmode == 'bridge') {
					$vm_settings['net0'] .= ',bridge=' . $plan->bridge . $plan->vmbr;
				}
				$vm_settings['net0'] .= ',firewall=' . $plan->firewall;
				if (!empty($plan->netrate)) {
					$vm_settings['net0'] .= ',rate=' . $plan->netrate;
				}
				if (!empty($plan->vlanid)) {
					$vm_settings['net0'] .= ',trunk=' . $plan->vlanid;
				}
				// IPv6: Same configs for second interface
				if (isset($vm_settings['ipconfig1'])) {
					$vm_settings['net1'] = $plan->netmodel;
					if ($plan->netmode == 'bridge') {
						$vm_settings['net1'] .= ',bridge=' . $plan->bridge . $plan->vmbr;
					}
					$vm_settings['net1'] .= ',firewall=' . $plan->firewall;
					if (!empty($plan->netrate)) {
						$vm_settings['net1'] .= ',rate=' . $plan->netrate;
					}
					if (!empty($plan->vlanid)) {
						$vm_settings['net1'] .= ',trunk=' . $plan->vlanid;
					}
				}
			}
		}

		$vm_settings['cpuunits'] = $plan->cpuunits;
		$vm_settings['cpulimit'] = $plan->cpulimit;
		$vm_settings['memory'] = $plan->memory;

		////////////////////////////////////////////////////
		// CREATION: Attempt to Create Guest via PVE2 API //
		////////////////////////////////////////////////////
		try {
			$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword);

			if ($proxmox->login()) {
				// Get first node name.
				$nodes = $proxmox->get_node_list();
				$first_node = $nodes[0];
				unset($nodes);

				if ($plan->vmtype == 'kvm') {
					$v = 'qemu';
				} else {
					$v = 'lxc';
				}

				// ACTION - Fire the attempt to create
				$logrequest = '/nodes/' . $first_node . '/' . $v . $vm_settings;
				$response = $proxmox->post('/nodes/' . $first_node . '/' . $v, $vm_settings);

				// DEBUG - Log the request parameters after it's fired
				if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
					logModuleCall(
						'pvewhmcs',
						__FUNCTION__,
						$logrequest,
						json_decode($response)
					);
				}

				// Extract UPID from the response (Proxmox returns colon-delimited string)
				if (strpos($response, 'UPID:') === 0) {
					$upid = trim($response); // Extract the entire UPID including "UPID:"

					// Poll for task completion
					$max_retries = 10;  // Total retries (avoid infinite loop)
					$retry_interval = 15;  // Number of seconds between retries
					$completed = false;

					for ($i = 0; $i < $max_retries; $i++) {
						// Check task status
						$task_status = $proxmox->get('/nodes/' . $first_node . '/tasks/' . $upid . '/status');

						if (isset($task_status['status']) && $task_status['status'] === 'stopped') {
							// Task is completed, now check exit status
							if (isset($task_status['exitstatus']) && $task_status['exitstatus'] === 'OK') {
								$completed = true;
								break;
							} else {
								// Task stopped, but failed with an exit status
								throw new Exception("Proxmox Error: Task failed with exit status: " . $task_status['exitstatus']);
							}
						} elseif ($task_status['status'] === 'running') {
							// Task is still running, wait and retry
							sleep($retry_interval);
						} else {
							// Unexpected task status
							throw new Exception("Proxmox Error: Unexpected task status: " . json_encode($task_status));
						}
					}

					if (!$completed) {
						throw new Exception("Proxmox Error: Task did not complete in time. Adjust ~/modules/servers/pvewhmcs/pvewhmcs.php >> max_retries option (2 locations).");
					}

					// Task is completed, now update the database with VM details.
					Capsule::table('mod_pvewhmcs_vms')->insert(
						[
							'id' => $params['serviceid'],
							'user_id' => $params['clientsdetails']['userid'],
							'vtype' => $v,
							'ipaddress' => $ip->ipaddress,
							'subnetmask' => $ip->mask,
							'gateway' => $ip->gateway,
							'created' => date("Y-m-d H:i:s"),
							'v6prefix' => $plan->ipv6,
						]
					);
					return true;
				} else {
					throw new Exception("Proxmox Error: Failed to initiate creation. Response: " . json_encode($response));
				}
			} else {
				throw new Exception("Proxmox Error: PVE API login failed. Please check your credentials.");
			}
		} catch (PVE2_Exception $e) {
			// Record the error in WHMCS's module log.
			if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
				logModuleCall(
					'pvewhmcs',
					__FUNCTION__,
					$params,
					$e->getMessage() . $e->getTraceAsString()
				);
			}
			return $e->getMessage();
		}
		unset($vm_settings);
	}
}

// PVE API FUNCTION, ADMIN: Test Connection with Proxmox node
function pvewhmcs_TestConnection(array $params) {
	try {
		// Call the service's connection test function
		$serverip = $params["serverip"];
		$serverusername = $params["serverusername"];
		$serverpassword = $params["serverpassword"];
		$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword);

		// Set success if login succeeded
		if ($proxmox->login()) {
			$success = true;
			$errorMsg = '';
		}
	} catch (Exception $e) {
		// Record the error in WHMCS's module log
		logModuleCall(
			'pvewhmcs',
			__FUNCTION__,
			$params,
			$e->getMessage(),
			$e->getTraceAsString()
		);
		// Set the error message as a failure
		$success = false;
		$errorMsg = $e->getMessage(); 
	}
	// Return success or error, and info
	return array(
		'success' => $success,
		'error' => $errorMsg,
	);
}

// PVE API FUNCTION, ADMIN: Suspend a Service on the hypervisor
function pvewhmcs_SuspendAccount(array $params) {
	$serverip = $params["serverip"];
	$serverusername = $params["serverusername"];
	$serverpassword = $params["serverpassword"];
	
	$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword);
	if ($proxmox->login()) {
		// Get first node name & prepare
		$nodes = $proxmox->get_node_list();
		$first_node = $nodes[0];
		unset($nodes);
		$guest=Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->get()[0] ;
		$pve_cmdparam = array();
		// Log and fire request
		$logrequest = '/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/stop';
		$response = $proxmox->post('/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/stop' , $pve_cmdparam);
	}
	// DEBUG - Log the request parameters before it's fired
	if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
		logModuleCall(
			'pvewhmcs',
			__FUNCTION__,
			$logrequest,
			json_encode($response)
		);
	}
	// Return success only if no errors returned by PVE
	if (isset($response) && !isset($response['errors'])) {
		return "success";
	} else {
		// Handle the case where there are errors
		$response_message = isset($response['errors']) ? json_encode($response['errors']) : "Unknown Error, consider using Debug Mode.";
		return "Error performing action. " . $response_message;
	}
}

// PVE API FUNCTION, ADMIN: Unsuspend a Service on the hypervisor
function pvewhmcs_UnsuspendAccount(array $params) {
	$serverip = $params["serverip"];
	$serverusername = $params["serverusername"];
	$serverpassword = $params["serverpassword"];
	
	$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword);
	if ($proxmox->login()) {
		// Get first node name & prepare
		$nodes = $proxmox->get_node_list();
		$first_node = $nodes[0];
		unset($nodes);
		$guest=Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->get()[0] ;
		$pve_cmdparam = array();
		// Log and fire request
		$logrequest = '/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/start';
		$response = $proxmox->post('/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/start');
	}
	// DEBUG - Log the request parameters before it's fired
	if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
		logModuleCall(
			'pvewhmcs',
			__FUNCTION__,
			$logrequest,
			json_encode($response)
		);
	}
	// Return success only if no errors returned by PVE
	if (isset($response) && !isset($response['errors'])) {
		return "success";
	} else {
		// Handle the case where there are errors
		$response_message = isset($response['errors']) ? json_encode($response['errors']) : "Unknown Error, consider using Debug Mode.";
		return "Error performing action. " . $response_message;
	}
}

// PVE API FUNCTION, ADMIN: Terminate a Service on the hypervisor
function pvewhmcs_TerminateAccount(array $params) {
	$serverip = $params["serverip"];
	$serverusername = $params["serverusername"];
	$serverpassword = $params["serverpassword"];

	$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword);
	if ($proxmox->login()){
		// Get first node name
		$nodes = $proxmox->get_node_list();
		$first_node = $nodes[0];
		unset($nodes);
		// Find virtual machine type
		$guest=Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->get()[0];
		$pve_cmdparam = array();
		// Stop the service if it is not already stopped
		$guest_specific = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'].'/status/current');
		if ($guest_specific['status'] != 'stopped') {
			$proxmox->post('/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/stop' , $pve_cmdparam);
			sleep(30);
		}

		if ($proxmox->delete('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'],array('skiplock'=>1))) {
			// Delete entry from module table once service terminated in PVE
			Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->delete();
			return "success";
		}
	}
	$response_message = json_encode($proxmox['data']['errors']);
	return "Error performing action. " . $response_message;
}

// GENERAL CLASS: WHMCS Decrypter
class hash_encryption {
	/**
	 * Hashed value of the user provided encryption key
	 * @var	string
	 **/
	var $hash_key;
	
	/**
	 * String length of hashed values using the current algorithm
	 * @var	int
	 **/
	var $hash_lenth;
	
	/**
	 * Switch base64 enconding on / off
	 * @var	bool	true = use base64, false = binary output / input
	 **/
	var $base64;
	
	/**
	 * Secret value added to randomize output and protect the user provided key
	 * @var	string	Change this value to add more randomness to your encryption
	 **/
	var $salt = 'Change this to any secret value you like. "d41d8cd98f00b204e9800998ecf8427e" might be a good example.';

	/**
	 * Constructor method
	 *
	 * Used to set key for encryption and decryption.
	 * @param	string	$key	Your secret key used for encryption and decryption
	 * @param	boold	$base64	Enable base64 en- / decoding
	 * @return mixed
	 */
	function hash_encryption($key, $base64 = true) {

		global $cc_encryption_hash;

		// Toggle base64 usage on / off
		$this->base64 = $base64;

		// Instead of using the key directly we compress it using a hash function
		$this->hash_key = $this->_hash($key);

		// Remember length of hashvalues for later use
		$this->hash_length = strlen($this->hash_key);
	}

	/**
	 * Method used for encryption
	 * @param	string	$string	Message to be encrypted
	 * @return string	Encrypted message
	 */
	function encrypt($string) {
		$iv = $this->_generate_iv();

		// Clear output
		$out = '';

		// First block of output is ($this->hash_hey XOR IV)
		for($c=0;$c < $this->hash_length;$c++) {
			$out .= chr(ord($iv[$c]) ^ ord($this->hash_key[$c]));
		}

		// Use IV as first key
		$key = $iv;
		$c = 0;

		// Go through input string
		while($c < strlen($string)) {
			// If we have used all characters of the current key we switch to a new one
			if(($c != 0) and ($c % $this->hash_length == 0)) {
				// New key is the hash of current key and last block of plaintext
				$key = $this->_hash($key . substr($string,$c - $this->hash_length,$this->hash_length));
			}
			// Generate output by xor-ing input and key character for character
			$out .= chr(ord($key[$c % $this->hash_length]) ^ ord($string[$c]));
			$c++;
		}
		// Apply base64 encoding if necessary
		if($this->base64) $out = base64_encode($out);
		return $out;
	}

	/**
	 * Method used for decryption
	 * @param	string	$string	Message to be decrypted
	 * @return string	Decrypted message
	 */
	function decrypt($string) {
		// Apply base64 decoding if necessary
		if($this->base64) $string = base64_decode($string);

		// Extract encrypted IV from input
		$tmp_iv = substr($string,0,$this->hash_length);

		// Extract encrypted message from input
		$string = substr($string,$this->hash_length,strlen($string) - $this->hash_length);
		$iv = $out = '';

		// Regenerate IV by xor-ing encrypted IV from block 1 and $this->hashed_key
		// Mathematics: (IV XOR KeY) XOR Key = IV
		for($c=0;$c < $this->hash_length;$c++)
		{
			$iv .= chr(ord($tmp_iv[$c]) ^ ord($this->hash_key[$c]));
		}
		// Use IV as key for decrypting the first block cyphertext
		$key = $iv;
		$c = 0;

		// Loop through the whole input string
		while($c < strlen($string)) {
			// If we have used all characters of the current key we switch to a new one
			if(($c != 0) and ($c % $this->hash_length == 0)) {
				// New key is the hash of current key and last block of plaintext
				$key = $this->_hash($key . substr($out,$c - $this->hash_length,$this->hash_length));
			}
			// Generate output by xor-ing input and key character for character
			$out .= chr(ord($key[$c % $this->hash_length]) ^ ord($string[$c]));
			$c++;
		}
		return $out;
	}

	/**
	 * Hashfunction used for encryption
	 *
	 * This class hashes any given string using the best available hash algorithm.
	 * Currently support for md5 and sha1 is provided. In theory even crc32 could be used
	 * but I don't recommend this.
	 *
	 * @access	private
	 * @param	string	$string	Message to hashed
	 * @return string	Hash value of input message
	 */
	function _hash($string) {
		// Use sha1() if possible, php versions >= 4.3.0 and 5
		if(function_exists('sha1')) {
			$hash = sha1($string);
		} else {
			// Fall back to md5(), php versions 3, 4, 5
			$hash = md5($string);
		}
		$out ='';
		// Convert hexadecimal hash value to binary string
		for($c=0;$c<strlen($hash);$c+=2) {
			$out .= $this->_hex2chr($hash[$c] . $hash[$c+1]);
		}
		return $out;
	}

	/**
	 * Generate a random string to initialize encryption
	 *
	 * This method will return a random binary string IV ( = initialization vector).
	 * The randomness of this string is one of the crucial points of this algorithm as it
	 * is the basis of encryption. The encrypted IV will be added to the encrypted message
	 * to make decryption possible. The transmitted IV will be encoded using the user provided key.
	 *
	 * @todo	Add more random sources.
	 * @access	private
	 * @see function	hash_encryption
	 * @return string	Binary pseudo random string
	 **/
	function _generate_iv() {
		// Initialize pseudo random generator
		srand ((double)microtime()*1000000);

		// Collect random data.
		// Add as many "pseudo" random sources as you can find.
		// Possible sources: Memory usage, diskusage, file and directory content...
		$iv  = $this->salt;
		$iv .= rand(0,getrandmax());
		// Changed to serialize as the second parameter to print_r is not available in php prior to version 4.4
		$iv .= serialize($GLOBALS);
		return $this->_hash($iv);
	}

	/**
	 * Convert hexadecimal value to a binary string
	 *
	 * This method converts any given hexadecimal number between 00 and ff to the corresponding ASCII char
	 *
	 * @access	private
	 * @param	string	Hexadecimal number between 00 and ff
	 * @return	string	Character representation of input value
	 **/
	function _hex2chr($num) {
		return chr(hexdec($num));
	}
}

// GENERAL FUNCTION: Server PW from WHMCS DB
function get_server_pass_from_whmcs($enc_pass){
	global $cc_encryption_hash;
	// Include WHMCS database configuration file
	include_once(dirname(dirname(dirname(dirname(__FILE__)))).'/configuration.php');
	$key1 = md5 (md5 ($cc_encryption_hash));
	$key2 = md5 ($cc_encryption_hash);
	$key = $key1.$key2;
	$hasher = new hash_encryption($key);
	return $hasher->decrypt($enc_pass);
}

// MODULE BUTTONS: Admin Interface button regos
function pvewhmcs_AdminCustomButtonArray() {
	$buttonarray = array(
		"Start" => "vmStart",
		"Reboot" => "vmReboot",
		"Soft Stop" => "vmShutdown",
		"Hard Stop" => "vmStop",
	);
	return $buttonarray;
}

// MODULE BUTTONS: Client Interface button regos
function pvewhmcs_ClientAreaCustomButtonArray() {
	$buttonarray = array(
		"<img src='./modules/servers/pvewhmcs/img/novnc.png'/> noVNC (HTML5)" => "noVNC",
		"<img src='./modules/servers/pvewhmcs/img/tigervnc.png'/> TigerVNC (Java)" => "javaVNC",
		"<i class='fa fa-2x fa-flag-checkered'></i> Start Machine" => "vmStart",
		"<i class='fa fa-2x fa-sync'></i> Reboot Now" => "vmReboot",
		"<i class='fa fa-2x fa-power-off'></i> Power Off" => "vmShutdown",
		"<i class='fa fa-2x fa-stop'></i>  Hard Stop" => "vmStop",
		"<i class='fa fa-2x fa-chart-bar'></i> View Statistics" => "redirectToVmStatsView",
        "<i class='fa fa-2x fa-cogs'></i> Kernel Configuration" => "redirectToKernelConfigView",
	);
	return $buttonarray;
}

// ACTION: Simple redirector function to show VM Stats view
function pvewhmcs_redirectToVmStatsView($params) {
    header("Location: clientarea.php?action=productdetails&id=" . $params['serviceid'] . "&modaction=vmstats");
    exit;
}

// ACTION: Simple redirector function to show Kernel Config view
function pvewhmcs_redirectToKernelConfigView($params) {
    header("Location: clientarea.php?action=productdetails&id=" . $params['serviceid'] . "&modaction=kernelconfig");
    exit;
}

// ACTION: Save Kernel Configuration by Client
function pvewhmcs_saveKernelConfig($params) {
    $serviceid = $params['serviceid'];
    $selected_os = $_POST['kernel_loader_os'];
    // $whmcsToken = $_POST['token']; // No longer needed directly, check_token() handles it

    // Verify CSRF token using WHMCS's standard function
    if (function_exists('check_token')) {
        check_token("WHMCS.default"); // This function will typically die() or redirect if the token is invalid.
    } else {
        // Fallback for environments where check_token might not be available (highly unlikely for client area)
        // Or if a manual check is absolutely preferred (less secure than WHMCS's own)
        $submitted_token = isset($_POST['token']) ? $_POST['token'] : '';
        if (!isset($_SESSION['tkval']) || empty($_SESSION['tkval']) || $submitted_token !== $_SESSION['tkval']) {
             if (function_exists('generate_token')) { $_SESSION['tkval'] = generate_token('plain'); } // Regenerate for next attempt
            header("Location: clientarea.php?action=productdetails&id=" . $serviceid . "&modaction=kernelconfig&kernelconfigerror=" . urlencode("Security token validation failed. Please try again."));
            exit;
        }
        // If manual check passes, regenerate token for next form load
        if (function_exists('generate_token')) {
            $_SESSION['tkval'] = generate_token('plain');
        }
    }
    // Note: generate_token() is called in pvewhmcs_ClientArea for the *next* page load.
    // check_token() consumes the current token.

    logModuleCall('pvewhmcs', __FUNCTION__ . ' - Start', 'POST Data: ' . json_encode($_POST), '');

    $new_bios_setting = '';
    $proxmox_ostype_setting = ''; // Actual OS type to send to Proxmox
    $db_ostype_setting = $selected_os; // Value to store in WHMCS DB for dropdown pre-selection

    switch ($selected_os) {
        case 'win10':
            $new_bios_setting = 'ovmf';
            $proxmox_ostype_setting = 'win10';
            break;
        case 'win11':
            $new_bios_setting = 'ovmf';
            $proxmox_ostype_setting = 'win11';
            break;
        case 'l26': // Generic Linux
            $new_bios_setting = 'seabios';
            $proxmox_ostype_setting = 'l26';
            break;
        case 'l26_centos':
            $new_bios_setting = 'seabios';
            $proxmox_ostype_setting = 'l26'; // Proxmox uses generic l26 for CentOS
            break;
        case 'l26_debian':
            $new_bios_setting = 'seabios';
            $proxmox_ostype_setting = 'l26'; // Proxmox uses generic l26 for Debian
            break;
        case 'l26_ubuntu':
            $new_bios_setting = 'seabios';
            $proxmox_ostype_setting = 'l26'; // Proxmox uses generic l26 for Ubuntu
            break;
        case 'solaris':
            $new_bios_setting = 'seabios';
            $proxmox_ostype_setting = 'solaris';
            break;
        case 'w2k':
            $new_bios_setting = 'seabios';
            $proxmox_ostype_setting = 'w2k';
            break;
        case 'w2k3':
            $new_bios_setting = 'seabios';
            $proxmox_ostype_setting = 'w2k3';
            break;
        case 'w2k8':
            $new_bios_setting = 'seabios';
            $proxmox_ostype_setting = 'w2k8';
            break;
        case 'other':
            $new_bios_setting = 'seabios';
            $proxmox_ostype_setting = 'other';
            break;
        default:
            // Regenerate token before redirecting due to error
            if (function_exists('generate_token')) { $_SESSION['tkval'] = generate_token('plain'); }
            header("Location: clientarea.php?action=productdetails&id=" . $serviceid . "&modaction=kernelconfig&kernelconfigerror=" . urlencode("Invalid OS selection."));
            exit;
    }

    logModuleCall('pvewhmcs', __FUNCTION__ . ' - Settings after switch', [
        'selected_os' => $selected_os,
        'proxmox_ostype_setting' => $proxmox_ostype_setting,
        'new_bios_setting' => $new_bios_setting,
        'db_ostype_setting' => $db_ostype_setting,
    ], '');

    try {
        // Gather access credentials for PVE
        $pveservice_details = Capsule::table('tblhosting')->find($serviceid);
        if (!$pveservice_details) {
            throw new Exception("Service not found in WHMCS.");
        }
        $pveserver_details = Capsule::table('tblservers')->where('id', '=', $pveservice_details->server)->first();
        if (!$pveserver_details) {
            throw new Exception("Server not found for this service.");
        }

        $serverip = $pveserver_details->ipaddress;
        $serverusername = $pveserver_details->username;
        // Decrypt password
        $api_data_pw = ['password2' => $pveserver_details->password];
        $decrypted_password_result = localAPI('DecryptPassword', $api_data_pw);
        $serverpassword = $decrypted_password_result['password'];

        if (empty($serverip) || empty($serverusername) || empty($serverpassword)) {
            throw new Exception("Proxmox server connection details are missing or incomplete.");
        }

        $proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword);

        if (!$proxmox->login()) {
            throw new Exception("Failed to connect to Proxmox server. Please check credentials.");
        }

        $nodes = $proxmox->get_node_list();
        if (empty($nodes)) {
            throw new Exception("No nodes found on Proxmox server.");
        }
        $first_node = $nodes[0];

        $guest_db_info = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $serviceid)->first();
        if (!$guest_db_info) {
            throw new Exception("VM not found in module database.");
        }
        $vtype = $guest_db_info->vtype; // qemu or lxc

        if ($vtype !== 'qemu') {
            throw new Exception("Kernel/loader configuration is only applicable to QEMU/KVM virtual machines.");
        }

        $config_params = [
            'ostype' => $proxmox_ostype_setting, // Ensure this uses the Proxmox-specific ostype
            'bios' => $new_bios_setting,
        ];
        logModuleCall('pvewhmcs', __FUNCTION__ . ' - API Config Params', $config_params, '');

        // Handle EFIDISK for OVMF
        if ($new_bios_setting == 'ovmf') {
            $current_config = $proxmox->get("/nodes/{$first_node}/{$vtype}/{$serviceid}/config");
            if (!isset($current_config['efidisk0'])) {
                // Attempt to add efidisk0 - this is a simplified approach.
                // Proxmox usually requires specifying the storage and format.
                // Example: 'local-lvm:1,format=raw,efitype=4m,pre-enrolled-keys=1'
                // This part might need more robust logic to find suitable storage or make it configurable.
                // For now, we'll try a common pattern; this might fail if 'local-lvm' isn't suitable or available.
                // A better solution would involve admin configuration for EFI disk storage.
                // $config_params['efidisk0'] = 'local-lvm:1,efitype=4m,pre-enrolled-keys=1';
                // For safety and to avoid breaking VMs, we will NOT attempt to auto-add efidisk0 without proper storage detection.
                // The user/admin should ensure OVMF compatible template or manual EFI disk setup if this module doesn't handle it.
                // We will only set bios and ostype. Proxmox might auto-create efidisk on some setups or error if not.
            }
        }


        $update_response = $proxmox->post("/nodes/{$first_node}/{$vtype}/{$serviceid}/config", $config_params);
        logModuleCall('pvewhmcs', __FUNCTION__ . ' (update_config)', "/nodes/{$first_node}/{$vtype}/{$serviceid}/config " . json_encode($config_params), $update_response);

        if (isset($update_response['errors'])) {
            throw new Exception("Error updating VM configuration on Proxmox: " . json_encode($update_response['errors']));
        }
        if (strpos((string)$update_response, 'UPID:') !== 0 && !empty($update_response)) { // Some PVE versions return UPID, some return empty on success for config update
             // Check if $update_response is an array and has data (for non-UPID success cases)
            if(is_array($update_response) && !empty($update_response['data'])){
                // Potentially successful, proceed
            } else if (empty($update_response)) {
                // Empty response can also be success for config updates
            }
             else {
                throw new Exception("Failed to update VM configuration. Unexpected response: " . json_encode($update_response));
            }
        }


        // Update local WHMCS database
        logModuleCall('pvewhmcs', __FUNCTION__ . ' - Before DB Update', ['service_id' => $serviceid, 'db_ostype_to_set' => $db_ostype_setting], '');
        try {
            Capsule::table('mod_pvewhmcs_vms')->where('id', $serviceid)->update(['ostype' => $db_ostype_setting]);
            logModuleCall('pvewhmcs', __FUNCTION__ . ' - After DB Update', 'Successfully updated mod_pvewhmcs_vms.ostype', '');
        } catch (Exception $dbEx) {
            logModuleCall('pvewhmcs', __FUNCTION__ . ' - DB Update Exception', $dbEx->getMessage(), $dbEx->getTraceAsString());
            // Decide if we should throw or just log and continue to reboot attempt
        }

        // Trigger reboot
        $reboot_params = []; // No specific params needed for reboot usually
        logModuleCall('pvewhmcs', __FUNCTION__ . ' - Before Reboot API call', "/nodes/{$first_node}/{$vtype}/{$serviceid}/status/reboot", '');
        $reboot_response = $proxmox->post("/nodes/{$first_node}/{$vtype}/{$serviceid}/status/reboot", $reboot_params);
        logModuleCall('pvewhmcs', __FUNCTION__ . ' (reboot_vm) - API Response', $reboot_response, '');

        if (isset($reboot_response['errors'])) {
            // Log reboot error but proceed with success message for config change
            logModuleCall('pvewhmcs', __FUNCTION__ . ' (reboot_vm_error)', "Error rebooting VM: " . json_encode($reboot_response['errors']), '');
        }
         if (strpos((string)$reboot_response, 'UPID:') !== 0 && !empty($reboot_response)) {
            if(is_array($reboot_response) && !empty($reboot_response['data'])){
                // Potentially successful reboot task started
                 logModuleCall('pvewhmcs', __FUNCTION__ . ' (reboot_vm_task_started_data)', $reboot_response['data'], '');
            } else if (empty($reboot_response)){
                // Empty response can be success for reboot task start
                 logModuleCall('pvewhmcs', __FUNCTION__ . ' (reboot_vm_task_started_empty_response)', '', '');
            }
            else {
                 logModuleCall('pvewhmcs', __FUNCTION__ . ' (reboot_vm_fail)', "Failed to initiate VM reboot. Unexpected response: " . json_encode($reboot_response), '');
            }
        }

        // Use $selected_os (original value from dropdown) for the user-facing message for clarity.
        $message = "Kernel/Loader configuration saved successfully. OS Type set to '{$selected_os}', BIOS set to '{$new_bios_setting}'. VM is being rebooted.";
        logModuleCall('pvewhmcs', __FUNCTION__ . ' - Success', $message, '');
        header("Location: clientarea.php?action=productdetails&id=" . $serviceid . "&modaction=kernelconfig&kernelconfigmessage=" . urlencode($message));

    } catch (Exception $e) {
        logModuleCall('pvewhmcs', __FUNCTION__ . ' - Exception Caught', $selected_os, $e->getMessage() . $e->getTraceAsString());
        // Regenerate token on error too
        if (function_exists('generate_token')) {
            $_SESSION['tkval'] = generate_token('plain');
        }
        header("Location: clientarea.php?action=productdetails&id=" . $serviceid . "&modaction=kernelconfig&kernelconfigerror=" . urlencode("Error: " . $e->getMessage()));
    }
    exit;
}


// OUTPUT: Module output to the Client Area
function pvewhmcs_ClientArea($params) {
	// Retrieve virtual machine info from table mod_pvewhmcs_vms
	$guest=Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->get()[0] ;
	
	// Gather access credentials for PVE, as these are no longer passed for Client Area
	$pveservice=Capsule::table('tblhosting')->find($params['serviceid']) ;
	$pveserver=Capsule::table('tblservers')->where('id','=',$pveservice->server)->get()[0] ;

	// Get IP and User for Hypervisor
	$serverip = $pveserver->ipaddress;
	$serverusername = $pveserver->username;
	// Password access is different in Client Area, so retrieve and decrypt
	$api_data = array(
		'password2' => $pveserver->password,
	);
	$serverpassword = localAPI('DecryptPassword', $api_data);

	$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword['password']);
	if ($proxmox->login()) {
		//$proxmox->setCookie();
		# Get first node name.
		$nodes = $proxmox->get_node_list();
		$first_node = $nodes[0];
		unset($nodes);

		# Get and set VM variables
		$vm_config = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/config') ;
		$cluster_resources = $proxmox->get('/cluster/resources');
		$vm_status = null;

		// DEBUG - Log the /cluster/resources and /config for the VM/CT, if enabled
		$cluster_encoded = json_encode($cluster_resources);
		$vmspecs_encoded = json_encode($vm_config);
		if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
			logModuleCall(
				'pvewhmcs',
				__FUNCTION__,
				'CLUSTER INFO: ' . $cluster_encoded,
				'GUEST CONFIG (Service #' . $params['serviceid'] . ' / Client #' . $params['clientsdetails']['userid'] . '): ' . $vmspecs_encoded
			);
		}

		# Loop through data, find ID
		foreach ($cluster_resources as $vm) {
			if ($vm['vmid'] == $params['serviceid'] && $vm['type'] == $guest->vtype) {
				$vm_status = $vm;
				break;
			}
		}

		# Set usage data appropriately
		if ($vm_status !== null) {
			$vm_status['uptime'] = time2format($vm_status['uptime']);
			$vm_status['cpu'] = round($vm_status['cpu'] * 100, 2);

			$vm_status['diskusepercent'] = ($vm_status['maxdisk'] > 0) ? intval($vm_status['disk'] * 100 / $vm_status['maxdisk']) : 0;
			$vm_status['memusepercent'] = ($vm_status['maxmem'] > 0) ? intval($vm_status['mem'] * 100 / $vm_status['maxmem']) : 0;


			if ($guest->vtype == 'lxc') {
				// Check on swap before setting graph value
				$ct_specific = $proxmox->get('/nodes/'.$first_node.'/lxc/'.$params['serviceid'].'/status/current');
				if ($ct_specific['maxswap'] != 0) {
					$vm_status['swapusepercent'] = intval($ct_specific['swap'] * 100 / $ct_specific['maxswap']);
				} else {
					// Fall back to 0% usage to satisfy chart requirement
					$vm_status['swapusepercent'] = 0;
				}
			} else { // For QEMU, swap might not be directly reported in the same way or relevant for this graph
                $vm_status['swapusepercent'] = 0; // Default to 0 for QEMU if not applicable/available
            }
		} else {
	    		// Handle the VM not found in the cluster resources (Optional)
			logModuleCall('pvewhmcs', __FUNCTION__, 'VM/CT not found in Cluster Resources for service ID: ' . $params['serviceid'], '');
            $vm_status = [ // Provide defaults to prevent errors in template
                'uptime' => 'N/A',
                'cpu' => 0,
                'diskusepercent' => 0,
                'memusepercent' => 0,
                'swapusepercent' => 0,
                'status' => 'unknown',
            ];
		}

        $vm_statistics = []; // Initialize to ensure it's an array

        // Populate VM statistics if requested by modaction=vmstats or if original vmStat action was called
        if (isset($params['modaction']) && $params['modaction'] == 'vmstats' || isset($params['vmStat']) || (isset($_GET['a']) && $_GET['a'] == 'vmStat')) {
            // Max CPU usage Yearly
            $rrd_params = '?timeframe=year&ds=cpu&cf=AVERAGE';
            $vm_rrd = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] . '/rrd' . $rrd_params) ;
            $vm_rrd['image'] = isset($vm_rrd['image']) ? utf8_decode($vm_rrd['image']) : '';
            $vm_statistics['cpu']['year'] = base64_encode($vm_rrd['image']);

            // Max CPU usage monthly
            $rrd_params = '?timeframe=month&ds=cpu&cf=AVERAGE';
            $vm_rrd = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/rrd'.$rrd_params) ;
            $vm_rrd['image'] = isset($vm_rrd['image']) ? utf8_decode($vm_rrd['image']) : '';
            $vm_statistics['cpu']['month'] = base64_encode($vm_rrd['image']);

            // Max CPU usage weekly
            $rrd_params = '?timeframe=week&ds=cpu&cf=AVERAGE';
            $vm_rrd = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/rrd'.$rrd_params) ;
            $vm_rrd['image'] = isset($vm_rrd['image']) ? utf8_decode($vm_rrd['image']) : '';
            $vm_statistics['cpu']['week'] = base64_encode($vm_rrd['image']);

            // Max CPU usage daily
            $rrd_params = '?timeframe=day&ds=cpu&cf=AVERAGE';
            $vm_rrd = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/rrd'.$rrd_params) ;
            $vm_rrd['image'] = isset($vm_rrd['image']) ? utf8_decode($vm_rrd['image']) : '';
            $vm_statistics['cpu']['day'] = base64_encode($vm_rrd['image']);

            // Max memory Yearly
            $rrd_params = '?timeframe=year&ds=maxmem&cf=AVERAGE';
            $vm_rrd = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/rrd'.$rrd_params) ;
            $vm_rrd['image'] = isset($vm_rrd['image']) ? utf8_decode($vm_rrd['image']) : '';
            $vm_statistics['maxmem']['year'] = base64_encode($vm_rrd['image']);

            // Max memory monthly
            $rrd_params = '?timeframe=month&ds=maxmem&cf=AVERAGE';
            $vm_rrd = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/rrd'.$rrd_params) ;
            $vm_rrd['image'] = isset($vm_rrd['image']) ? utf8_decode($vm_rrd['image']) : '';
            $vm_statistics['maxmem']['month'] = base64_encode($vm_rrd['image']);

            // Max memory weekly
            $rrd_params = '?timeframe=week&ds=maxmem&cf=AVERAGE';
            $vm_rrd = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/rrd'.$rrd_params) ;
            $vm_rrd['image'] = isset($vm_rrd['image']) ? utf8_decode($vm_rrd['image']) : '';
            $vm_statistics['maxmem']['week'] = base64_encode($vm_rrd['image']);

            // Max memory daily
            $rrd_params = '?timeframe=day&ds=maxmem&cf=AVERAGE';
            $vm_rrd = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/rrd'.$rrd_params) ;
            $vm_rrd['image'] = isset($vm_rrd['image']) ? utf8_decode($vm_rrd['image']) : '';
            $vm_statistics['maxmem']['day'] = base64_encode($vm_rrd['image']);

            // Network rate Yearly
            $rrd_params = '?timeframe=year&ds=netin,netout&cf=AVERAGE';
            $vm_rrd = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/rrd'.$rrd_params) ;
            $vm_rrd['image'] = isset($vm_rrd['image']) ? utf8_decode($vm_rrd['image']) : '';
            $vm_statistics['netinout']['year'] = base64_encode($vm_rrd['image']);

            // Network rate monthly
            $rrd_params = '?timeframe=month&ds=netin,netout&cf=AVERAGE';
            $vm_rrd = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/rrd'.$rrd_params) ;
            $vm_rrd['image'] = isset($vm_rrd['image']) ? utf8_decode($vm_rrd['image']) : '';
            $vm_statistics['netinout']['month'] = base64_encode($vm_rrd['image']);

            // Network rate weekly
            $rrd_params = '?timeframe=week&ds=netin,netout&cf=AVERAGE';
            $vm_rrd = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/rrd'.$rrd_params) ;
            $vm_rrd['image'] = isset($vm_rrd['image']) ? utf8_decode($vm_rrd['image']) : '';
            $vm_statistics['netinout']['week'] = base64_encode($vm_rrd['image']);

            // Network rate daily
            $rrd_params = '?timeframe=day&ds=netin,netout&cf=AVERAGE';
            $vm_rrd = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/rrd'.$rrd_params) ;
            $vm_rrd['image'] = isset($vm_rrd['image']) ? utf8_decode($vm_rrd['image']) : '';
            $vm_statistics['netinout']['day'] = base64_encode($vm_rrd['image']);

            // Max IO Yearly
            $rrd_params = '?timeframe=year&ds=diskread,diskwrite&cf=AVERAGE';
            $vm_rrd = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/rrd'.$rrd_params) ;
            $vm_rrd['image'] = isset($vm_rrd['image']) ? utf8_decode($vm_rrd['image']) : '';
            $vm_statistics['diskrw']['year'] = base64_encode($vm_rrd['image']);

            // Max IO monthly
            $rrd_params = '?timeframe=month&ds=diskread,diskwrite&cf=AVERAGE';
            $vm_rrd = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/rrd'.$rrd_params) ;
            $vm_rrd['image'] = isset($vm_rrd['image']) ? utf8_decode($vm_rrd['image']) : '';
            $vm_statistics['diskrw']['month'] = base64_encode($vm_rrd['image']);

            // Max IO weekly
            $rrd_params = '?timeframe=week&ds=diskread,diskwrite&cf=AVERAGE';
            $vm_rrd = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/rrd'.$rrd_params) ;
            $vm_rrd['image'] = isset($vm_rrd['image']) ? utf8_decode($vm_rrd['image']) : '';
            $vm_statistics['diskrw']['week'] = base64_encode($vm_rrd['image']);

            // Max IO daily
            $rrd_params = '?timeframe=day&ds=diskread,diskwrite&cf=AVERAGE';
            $vm_rrd = $proxmox->get('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/rrd'.$rrd_params) ;
            $vm_rrd['image'] = isset($vm_rrd['image']) ? utf8_decode($vm_rrd['image']) : '';
            $vm_statistics['diskrw']['day'] = base64_encode($vm_rrd['image']);

            unset($vm_rrd);
        }

		$vm_config['vtype'] = $guest->vtype ;
		$vm_config['ipv4'] = $guest->ipaddress ;
		$vm_config['netmask4'] = $guest->subnetmask ;
		$vm_config['gateway4'] = $guest->gateway ;
		$vm_config['created'] = $guest->created ;
		$vm_config['v6prefix'] = $guest->v6prefix ;
	}
	else {
		echo '<center><strong>Unable to contact Hypervisor - aborting!<br>Please contact Tech Support.</strong></center>'; 
		exit;
	}

    // Ensure CSRF token is available for forms
    if (function_exists('generate_token')) {
        // WHMCS typically stores the token in $_SESSION['tkval'] when generate_token is called.
        // If it's not already set or needs refreshing for this page load:
        if (empty($_SESSION['tkval'])) {
            $_SESSION['tkval'] = generate_token('plain');
        }
    }
    // The template uses {$smarty.session.tkval}, which should work if the session variable is set.
    // Alternatively, pass it directly if Smarty version/config requires it:
    // $smartyvalues = array('token' => $_SESSION['tkval']); // And merge into 'vars'

    // Initialize $vm_vncproxy if it might not be set to avoid template errors if API login failed earlier
    if (!isset($vm_vncproxy)) {
        $vm_vncproxy = null;
    }

    $template_vars = array(
        'params' => $params, // serviceid is in $params['serviceid']
        'vm_config' => $vm_config,
        'vm_status' => $vm_status,
        'vm_statistics' => $vm_statistics,
        'vm_vncproxy' => $vm_vncproxy,
        'csrf_token' => '', // Default to empty
    );

    if (function_exists('generate_token')) {
        $template_vars['csrf_token'] = generate_token('plain');
    }

	return array(
		'templatefile' => 'clientarea',
		'vars' => $template_vars,
	);
}

// OUTPUT: VM Statistics/Graphs render to Client Area
function pvewhmcs_vmStat($params) {
	return true;
}

// VNC: Console access to VM/CT via noVNC
function pvewhmcs_noVNC($params) {
	// Check if VNC Secret is configured in Module Config, fail early if not. (#27)
	if (strlen(Capsule::table('mod_pvewhmcs')->where('id', '1')->value('vnc_secret'))<15) {
		throw new Exception("PVEWHMCS Error: VNC Secret in Module Config either not set or not long enough. Recommend 20+ characters for security.");
	}
	
	// Get login credentials then make the Proxmox connection attempt.
	$serverip = $params["serverip"];
	$serverusername = 'vnc';
	$serverpassword = Capsule::table('mod_pvewhmcs')->where('id', '1')->value('vnc_secret');
	
	$proxmox = new PVE2_API($serverip, $serverusername, "pve", $serverpassword);
	if ($proxmox->login()) {
		// Get first node name
		$nodes = $proxmox->get_node_list();
		$first_node = $nodes[0];
		unset($nodes);
		// Early prep work
		$guest = Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->get()[0] ;
		$vm_vncproxy = $proxmox->post('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/vncproxy', array( 'websocket' => '1' )) ;
		// Get both tickets prepared
		$pveticket = $proxmox->getTicket();
		$vncticket = $vm_vncproxy['ticket'];
		// $path should only contain the actual path without any query parameters
		$path = 'api2/json/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/vncwebsocket?port=' . $vm_vncproxy['port'] . '&vncticket=' . urlencode($vncticket);
		// Construct the noVNC Router URL with the path already prepared now
		$url = '/modules/servers/pvewhmcs/novnc_router.php?host=' . $serverip . '&pveticket=' . urlencode($pveticket) . '&path=' . urlencode($path) . '&vncticket=' . urlencode($vncticket);
		// Build and deliver the noVNC Router hyperlink for access
		$vncreply = '<center><strong>Console (noVNC) prepared for usage. <a href="'.$url.'" target="_blanK">Click here</a> to open the noVNC window.</strong></center>' ;
		return $vncreply;
	} else {
		$vncreply = 'Failed to prepare noVNC. Please contact Technical Support.';
		return $vncreply;
	}
}

// VNC: Console access to VM/CT via SPICE
function pvewhmcs_SPICE($params) {
	// Check if VNC Secret is configured in Module Config, fail early if not. (#27)
	if (strlen(Capsule::table('mod_pvewhmcs')->where('id', '1')->value('vnc_secret'))<15) {
		throw new Exception("PVEWHMCS Error: VNC Secret in Module Config either not set or not long enough. Recommend 20+ characters for security.");
	}
	
	// Get login credentials then make the Proxmox connection attempt.
	$serverip = $params["serverip"];
	$serverusername = 'vnc';
	$serverpassword = Capsule::table('mod_pvewhmcs')->where('id', '1')->value('vnc_secret');
	
	$proxmox = new PVE2_API($serverip, $serverusername, "pve", $serverpassword);
	if ($proxmox->login()) {
		// Get first node name
		$nodes = $proxmox->get_node_list();
		$first_node = $nodes[0];
		unset($nodes);
		// Early prep work
		$guest = Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->get()[0] ;
		$vm_vncproxy = $proxmox->post('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/vncproxy', array( 'websocket' => '1' )) ;
		// Get both tickets prepared
		$pveticket = $proxmox->getTicket();
		$vncticket = $vm_vncproxy['ticket'];
		// $path should only contain the actual path without any query parameters
		$path = 'api2/json/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/vncwebsocket?port=' . $vm_vncproxy['port'] . '&vncticket=' . urlencode($vncticket);
		// Construct the SPICE Router URL with the path already prepared now
		$url = '/modules/servers/pvewhmcs/spice_router.php?host=' . $serverip . '&pveticket=' . urlencode($pveticket) . '&path=' . urlencode($path) . '&vncticket=' . urlencode($vncticket);
		// Build and deliver the SPICE Router hyperlink for access
		$vncreply = '<center><strong>Console (SPICE) prepared for usage. <a href="'.$url.'" target="_blanK">Click here</a> to open the noVNC window.</strong></center>' ;
		return $vncreply;
	} else {
		$vncreply = 'Failed to prepare SPICE. Please contact Technical Support.';
		return $vncreply;
	}
}

// VNC: Console access to VM/CT via TigerVNC
function pvewhmcs_javaVNC($params){
	// Check if VNC Secret is configured in Module Config, fail early if not. (#27)
	if (strlen(Capsule::table('mod_pvewhmcs')->where('id', '1')->value('vnc_secret'))<15) {
		throw new Exception("PVEWHMCS Error: VNC Secret in Module Config either not set or not long enough. Recommend 20+ characters for security.");
	}
	// Get login credentials then make the Proxmox connection attempt.
	$serverip = $params["serverip"];
	$serverusername = 'vnc';
	$serverpassword = Capsule::table('mod_pvewhmcs')->where('id', '1')->value('vnc_secret');
	$proxmox = new PVE2_API($serverip, $serverusername, "pve", $serverpassword);
	if ($proxmox->login()) {
		// Get first node name
		$nodes = $proxmox->get_node_list();
		$first_node = $nodes[0];
		unset($nodes);
		// Early prep work
		$guest = Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->get()[0] ;
		$vncparams = array();
		$vm_vncproxy = $proxmox->post('/nodes/'.$first_node.'/'.$guest->vtype.'/'.$params['serviceid'] .'/vncproxy', $vncparams) ;
		// Java-specific params
		$javaVNCparams = array() ;
		$javaVNCparams[0] = $serverip ;
		$javaVNCparams[1] = str_replace("\n","|",$vm_vncproxy['cert']) ;
		$javaVNCparams[2] = $vm_vncproxy['port'] ;
		$javaVNCparams[3] = $vm_vncproxy['user'] ;
		$javaVNCparams[4] = $vm_vncproxy['ticket'] ;
		// URL preparation to deliver in hyperlink message
		$url = './modules/servers/pvewhmcs/tigervnc.php?'.http_build_query($javaVNCparams).'' ;
		$vncreply = '<center><strong>Console (TigerVNC) prepared for usage. <a href="'.$url.'" target="_blanK">Click here</a> to open the TigerVNC window.</strong></center>' ;
		// echo '<script>window.open("modules/servers/pvewhmcs/tigervnc.php?'.http_build_query($javaVNCparams).'","VNC","location=0,toolbar=0,menubar=0,scrollbars=1,resizable=1,width=802,height=624")</script>';
		return $vncreply;
	} else {
		$vncreply = 'Failed to prepare TigerVNC. Please contact Technical Support.';
		return $vncreply;
	}
}

// PVE API FUNCTION, CLIENT/ADMIN: Start the VM/CT
function pvewhmcs_vmStart($params) {
	// Gather access credentials for PVE, as these are no longer passed for Client Area
	$pveservice = Capsule::table('tblhosting')->find($params['serviceid']) ;
	$pveserver = Capsule::table('tblservers')->where('id','=',$pveservice->server)->get()[0] ;
	$serverip = $pveserver->ipaddress;
	$serverusername = $pveserver->username;

	$api_data = array(
		'password2' => $pveserver->password,
	);
	$serverpassword = localAPI('DecryptPassword', $api_data);
	$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword['password']);
	if ($proxmox->login()) {
		# Get first node name.
		$nodes = $proxmox->get_node_list();
		$first_node = $nodes[0];
		unset($nodes);
		$guest = Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->get()[0] ;
		$pve_cmdparam = array();
		$logrequest = '/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/start';
		$response = $proxmox->post('/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/start' , $pve_cmdparam);
	}
	// DEBUG - Log the request parameters before it's fired
	if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
		logModuleCall(
			'pvewhmcs',
			__FUNCTION__,
			$logrequest,
			json_encode($response)
		);
	}
	// Return success only if no errors returned by PVE
	if (isset($response) && !isset($response['errors'])) {
		return "success";
	} else {
		// Handle the case where there are errors
		$response_message = isset($response['errors']) ? json_encode($response['errors']) : "Unknown Error, consider using Debug Mode.";
		return "Error performing action. " . $response_message;
	}
}

// PVE API FUNCTION, CLIENT/ADMIN: Reboot the VM/CT
function pvewhmcs_vmReboot($params) {
	// Gather access credentials for PVE, as these are no longer passed for Client Area
	$pveservice = Capsule::table('tblhosting')->find($params['serviceid']) ;
	$pveserver = Capsule::table('tblservers')->where('id','=',$pveservice->server)->get()[0] ;
	$serverip = $pveserver->ipaddress;
	$serverusername = $pveserver->username;

	$api_data = array(
		'password2' => $pveserver->password,
	);
	$serverpassword = localAPI('DecryptPassword', $api_data);
	$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword['password']);
	if ($proxmox->login()) {
		# Get first node name.
		$nodes = $proxmox->get_node_list();
		$first_node = $nodes[0];
		unset($nodes);
		$guest = Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->get()[0] ;
		$pve_cmdparam = array();
		// Check status before doing anything
		$guest_specific = $proxmox->get('/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/current');
        	if ($guest_specific['status'] = 'stopped') {
			// START if Stopped
			$logrequest = '/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/start';
			$response = $proxmox->post('/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/start' , $pve_cmdparam);
		} else {
			// REBOOT if Started
			$logrequest = '/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/reboot';
			$response = $proxmox->post('/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/reboot' , $pve_cmdparam);
		}
	}
	// DEBUG - Log the request parameters before it's fired
	if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
		logModuleCall(
			'pvewhmcs',
			__FUNCTION__,
			$logrequest,
			json_encode($response)
		);
	}
	// Return success only if no errors returned by PVE
	if (isset($response) && !isset($response['errors'])) {
		return "success";
	} else {
		// Handle the case where there are errors
		$response_message = isset($response['errors']) ? json_encode($response['errors']) : "Unknown Error, consider using Debug Mode.";
		return "Error performing action. " . $response_message;
	}
}

// PVE API FUNCTION, CLIENT/ADMIN: Shutdown the VM/CT
function pvewhmcs_vmShutdown($params) {
	// Gather access credentials for PVE, as these are no longer passed for Client Area
	$pveservice = Capsule::table('tblhosting')->find($params['serviceid']) ;
	$pveserver = Capsule::table('tblservers')->where('id','=',$pveservice->server)->get()[0] ;
	
	$serverip = $pveserver->ipaddress;
	$serverusername = $pveserver->username;

	$api_data = array(
		'password2' => $pveserver->password,
	);
	$serverpassword = localAPI('DecryptPassword', $api_data);
	$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword['password']);
	if ($proxmox->login()) {
		# Get first node name.
		$nodes = $proxmox->get_node_list();
		$first_node = $nodes[0];
		unset($nodes);
		$guest = Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->get()[0] ;
		$pve_cmdparam = array();
		// $pve_cmdparam['timeout'] = '60';
		$logrequest = '/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/shutdown';
		$response = $proxmox->post('/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/shutdown' , $pve_cmdparam);
	}
	// DEBUG - Log the request parameters before it's fired
	if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
		logModuleCall(
			'pvewhmcs',
			__FUNCTION__,
			$logrequest,
			json_encode($response)
		);
	}
	// Return success only if no errors returned by PVE
	if (isset($response) && !isset($response['errors'])) {
		return "success";
	} else {
		// Handle the case where there are errors
		$response_message = isset($response['errors']) ? json_encode($response['errors']) : "Unknown Error, consider using Debug Mode.";
		return "Error performing action. " . $response_message;
	}
}

// PVE API FUNCTION, CLIENT/ADMIN: Stop the VM/CT
function pvewhmcs_vmStop($params) {
	// Gather access credentials for PVE, as these are no longer passed for Client Area
	$pveservice = Capsule::table('tblhosting')->find($params['serviceid']) ;
	$pveserver = Capsule::table('tblservers')->where('id','=',$pveservice->server)->get()[0] ;
	$serverip = $pveserver->ipaddress;
	$serverusername = $pveserver->username;

	$api_data = array(
		'password2' => $pveserver->password,
	);
	$serverpassword = localAPI('DecryptPassword', $api_data);
	$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword['password']);
	if ($proxmox->login()) {
		# Get first node name.
		$nodes = $proxmox->get_node_list();
		$first_node = $nodes[0];
		unset($nodes);
		$guest = Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->get()[0] ;
		$pve_cmdparam = array();
		// $pve_cmdparam['timeout'] = '60';
		$logrequest = '/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/stop';
		$response = $proxmox->post('/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/stop' , $pve_cmdparam);
	}
	// DEBUG - Log the request parameters before it's fired
	if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
		logModuleCall(
			'pvewhmcs',
			__FUNCTION__,
			$logrequest,
			json_encode($response)
		);
	}
	// Return success only if no errors returned by PVE
	if (isset($response) && !isset($response['errors'])) {
		return "success";
	} else {
		// Handle the case where there are errors
		$response_message = isset($response['errors']) ? json_encode($response['errors']) : "Unknown Error, consider using Debug Mode.";
		return "Error performing action. " . $response_message;
	}
}

// NETWORKING FUNCTION: Convert subnet mask to CIDR
function mask2cidr($mask){
	$long = ip2long($mask);
	$base = ip2long('255.255.255.255');
	return 32-log(($long ^ $base)+1,2);
}

function bytes2format($bytes, $precision = 2, $_1024 = true) {
	$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
	$bytes = max( $bytes, 0 );
	$pow = floor( ($bytes ? log( $bytes ) : 0) / log( ($_1024 ? 1024 : 1000) ) );
	$pow = min( $pow, count( $units ) - 1 );
	$bytes /= pow( ($_1024 ? 1024 : 1000), $pow );
	return round( $bytes, $precision ) . ' ' . $units[$pow];
}

function time2format($s) {
	$d = intval( $s / 86400 );
	if ($d < '10') {
		$d = '0' . $d;
	}
	$s -= $d * 86400;
	$h = intval( $s / 3600 );
	if ($h < '10') {
		$h = '0' . $h;
	}
	$s -= $h * 3600;
	$m = intval( $s / 60 );
	if ($m < '10') {
		$m = '0' . $m;
	}
	$s -= $m * 60;
	if ($s < '10') {
		$s = '0' . $s;
	}
	if ($d) {
		$str = $d . ' days ';
	}
	if ($h) {
		$str .= $h . ':';
	}
	if ($m) {
		$str .= $m . ':';
	}
	if ($s) {
		$str .= $s . '';
	}
	return $str;
}
?>
