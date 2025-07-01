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
                // Apply custom args if provided via custom field during KVM clone
                if (!empty($params['customfields']['CustomProxmoxArgs'])) {
                    $cloned_tweaks['args'] = $params['customfields']['CustomProxmoxArgs'];
                }
				$amendment = $proxmox->post('/nodes/' . $first_node . '/qemu/' . $vm_settings['newid'] . '/config', $cloned_tweaks);

                // Update local DB with custom_args if set from custom field
                $update_local_db_params = [];
                if (!empty($params['customfields']['CustomProxmoxArgs'])) {
                    $update_local_db_params['custom_args'] = $params['customfields']['CustomProxmoxArgs'];
                }
                // Also ensure ostype from plan is saved for KVM template clones
                if (!empty($plan->ostype)) {
                    $update_local_db_params['ostype'] = $plan->ostype;
                }
                if (!empty($update_local_db_params)) {
                    Capsule::table('mod_pvewhmcs_vms')->where('id', $params['serviceid'])->update($update_local_db_params);
                }
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
					$vm_settings['net1'] .= ',tag=' . $plan->vlanid;
				}
			}
			if (!empty($plan->vlanid)) {
				$vm_settings['net0'] .= ',tag=' . $plan->vlanid;
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
					$vm_settings['net0'] .= ',tag=' . $plan->vlanid;
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
						$vm_settings['net1'] .= ',tag=' . $plan->vlanid;
					}
				}
			}
		}

		$vm_settings['cpuunits'] = $plan->cpuunits;
		$vm_settings['cpulimit'] = $plan->cpulimit;
		$vm_settings['memory'] = $plan->memory;

        // Add custom arguments if provided via custom field for non-template QEMU/LXC
        if (!empty($params['customfields']['CustomProxmoxArgs']) && $plan->vmtype == 'qemu') { // LXC does not use 'args' in the same way QEMU does.
            $vm_settings['args'] = $params['customfields']['CustomProxmoxArgs'];
        }

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
                            'custom_args' => (!empty($params['customfields']['CustomProxmoxArgs']) && $v == 'qemu') ? $params['customfields']['CustomProxmoxArgs'] : null,
                            'ostype' => ($v == 'qemu') ? $plan->ostype : null, // Save ostype for QEMU, null for LXC from plan
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
		"<i class='fa fa-2x fa-flag-checkered'></i> &nbsp; Start Machine" => "vmStart",
		"<i class='fa fa-2x fa-sync'></i> &nbsp; Reboot Now" => "vmReboot",
		"<i class='fa fa-2x fa-power-off'></i> &nbsp; Power Off" => "vmShutdown",
		"<i class='fa fa-2x fa-stop'></i> &nbsp; Hard Stop" => "vmStop",
		"<i class='fa fa-2x fa-compact-disc'></i> &nbsp; Load Iso" => "loadIsoPage",
	        "<i class='fa fa-2x fa-cogs'></i> Kernel Configuration" => "redirectToKernelConfigView",
		"<i class='fa fa-2x fa-sort-amount-up'></i> &nbsp; Boot Order" => "loadBootOrderPage",
		"<i class='fa fa-2x fa-terminal'></i> &nbsp; Advanced Boot Options" => "loadCustomArgsPage",
		"<i class='fa fa-2x fa-chart-bar'></i> &nbsp; Statistics" => "vmStat",
	);
	return $buttonarray;
}

function pvewhmcs_ClientAreaAllowedFunctions() {
    return ['mountIso', 'loadIsoPage', 'umountIso', 'unmountIso', 'saveKernelConfig', 'loadBootOrderPage', 'saveBootOrderConfig', 'loadCustomArgsPage', 'saveCustomArgsConfig'];
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

// ACTION: Display Boot Order Configuration Page
function pvewhmcs_loadBootOrderPage($params) {
    // Gather access credentials for PVE
    $pveservice = Capsule::table('tblhosting')->find($params['serviceid']);
    $pveserver = Capsule::table('tblservers')->where('id', '=', $pveservice->server)->first();

    $serverip = $pveserver->ipaddress;
    $serverusername = $pveserver->username;
    $api_data = array('password2' => $pveserver->password);
    $serverpassword_decrypted = localAPI('DecryptPassword', $api_data);
    $serverpassword = $serverpassword_decrypted['password'];

    $proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword);

    $current_boot_order = '';
    $available_devices = [];
    $error_message = null;
    $action_message = null; // For messages from save operation

    if (isset($_SESSION['pvewhmcs_boot_order_message'])) {
        $action_message = $_SESSION['pvewhmcs_boot_order_message'];
        unset($_SESSION['pvewhmcs_boot_order_message']);
    }

    if ($proxmox->login()) {
        $nodes = $proxmox->get_node_list();
        if (!empty($nodes) && isset($nodes[0])) {
            $first_node = $nodes[0];
            $guest_info = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->first();
            $vm_type = $guest_info->vtype;

            if ($vm_type == 'qemu') {
                $vm_config = $proxmox->get("/nodes/{$first_node}/qemu/{$params['serviceid']}/config");
                if ($vm_config) {
                    $current_boot_order = isset($vm_config['boot']) ? $vm_config['boot'] : '';
                    // Extract devices from config - this is a simplified list.
                    // A more robust way would be to check for ideX, scsiX, virtioX, netX etc.
                    // For now, let's list common ones that might appear in boot order.
                    $possible_boot_devices = ['ide0', 'ide1', 'ide2', 'ide3', 'scsi0', 'scsi1', 'scsi2', 'virtio0', 'virtio1', 'net0', 'net1'];
                    foreach ($possible_boot_devices as $dev) {
                        if (isset($vm_config[$dev])) {
                            // We only care that the device exists in config, not its details for this purpose.
                            $available_devices[] = $dev;
                        }
                    }
                    // Ensure devices currently in boot order are also listed if not caught by above
                    if (!empty($current_boot_order) && strpos($current_boot_order, 'order=') === 0) {
                        $order_str = substr($current_boot_order, strlen('order='));
                        $ordered_devs = explode(';', $order_str);
                        foreach($ordered_devs as $dev) {
                            if (!empty($dev) && !in_array($dev, $available_devices)) {
                                $available_devices[] = $dev; // Add it if it's in boot order but not found in simple config scan
                            }
                        }
                    }


                } else {
                    $error_message = "Could not retrieve VM configuration.";
                }
            } else {
                $error_message = "Boot order configuration is only applicable to QEMU/KVM virtual machines.";
            }
        } else {
            $error_message = "Could not retrieve node list from Proxmox.";
        }
    } else {
        $error_message = "Failed to login to Proxmox API. Please check credentials and connectivity.";
    }

    $csrf_token = '';
    if (function_exists('generate_token')) {
        $csrf_token = generate_token('plain');
    }


    return array(
        'templatefile' => 'load_boot_order_area',
        'vars' => array(
            'params' => $params,
            'current_boot_order_raw' => $current_boot_order,
            'available_devices' => $available_devices,
            'error_message' => $error_message,
            'action_message' => $action_message,
            'csrf_token' => $csrf_token,
        ),
    );
}

// ACTION: Save Custom QEMU Arguments by Client
function pvewhmcs_saveCustomArgsConfig($params) {
    session_start(); // Required to pass messages back via session
    $serviceid = $params['serviceid'];

    // Verify CSRF token
    if (function_exists('check_token')) {
        check_token("WHMCS.default");
    } else {
        $submitted_token = isset($_POST['token']) ? $_POST['token'] : '';
        if (!isset($_SESSION['tkval']) || empty($_SESSION['tkval']) || $submitted_token !== $_SESSION['tkval']) {
            if (function_exists('generate_token')) { $_SESSION['tkval'] = generate_token('plain'); }
            $_SESSION['pvewhmcs_custom_args_message'] = "Error: Security token validation failed. Please try again.";
            header("Location: clientarea.php?action=productdetails&id=" . $serviceid . "&modop=custom&a=loadCustomArgsPage");
            exit;
        }
        if (function_exists('generate_token')) { $_SESSION['tkval'] = generate_token('plain'); }
    }

    $custom_args_string = isset($_POST['custom_qemu_args']) ? trim($_POST['custom_qemu_args']) : "";
    // If string is empty after trim, effectively means user wants to remove custom args.
    // Proxmox handles an empty 'args' value by removing it from the config.

    logModuleCall('pvewhmcs', __FUNCTION__ . ' - Custom Args to be set', $custom_args_string, $_POST);

    try {
        $pveservice_details = Capsule::table('tblhosting')->find($serviceid);
        if (!$pveservice_details) throw new Exception("Service not found.");
        $pveserver_details = Capsule::table('tblservers')->where('id', '=', $pveservice_details->server)->first();
        if (!$pveserver_details) throw new Exception("Server not found for service.");

        $serverip = $pveserver_details->ipaddress;
        $serverusername = $pveserver_details->username;
        $api_data_pw = ['password2' => $pveserver_details->password];
        $decrypted_password_result = localAPI('DecryptPassword', $api_data_pw);
        $serverpassword = $decrypted_password_result['password'];

        $proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword);

        if (!$proxmox->login()) throw new Exception("Failed to connect to Proxmox server.");

        $nodes = $proxmox->get_node_list();
        if (empty($nodes)) throw new Exception("No nodes found on Proxmox server.");
        $first_node = $nodes[0];

        $guest_db_info = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $serviceid)->first();
        if (!$guest_db_info) throw new Exception("VM not found in module database.");
        if ($guest_db_info->vtype !== 'qemu') throw new Exception("Custom QEMU arguments are only for QEMU/KVM VMs.");

        // Update local database
        Capsule::table('mod_pvewhmcs_vms')->where('id', $serviceid)->update(['custom_args' => $custom_args_string]);
        logModuleCall('pvewhmcs', __FUNCTION__ . ' - Local DB updated with custom_args', $custom_args_string, '');

        // Update Proxmox server config
        // Sending an empty string for 'args' should make Proxmox remove the args line from the config file.
        $config_params = ['args' => $custom_args_string];
        $update_response = $proxmox->put("/nodes/{$first_node}/qemu/{$serviceid}/config", $config_params);
        logModuleCall('pvewhmcs', __FUNCTION__ . ' (Proxmox update_config)', "/nodes/{$first_node}/qemu/{$serviceid}/config " . json_encode($config_params), $update_response);

        if ($update_response !== true) {
            $api_error_message = "Failed to update custom QEMU arguments on Proxmox.";
            if(is_array($update_response) && isset($update_response['errors'])) {
                $api_error_message .= " Details: " . json_encode($update_response['errors']);
            } else if (is_string($update_response) && !empty($update_response)) {
                $api_error_message .= " Details: " . htmlentities($update_response);
            }
            // Rollback local DB change if Proxmox update failed? For simplicity, we'll report error and leave DB as is.
            // User can try again. Or, fetch original value before local update and revert on PVE failure.
            throw new Exception($api_error_message);
        }

        $_SESSION['pvewhmcs_custom_args_message'] = "Success: Custom QEMU arguments updated. A reboot may be required for changes to take full effect.";

    } catch (Exception $e) {
        logModuleCall('pvewhmcs', __FUNCTION__ . ' - Exception Caught', $custom_args_string, $e->getMessage() . $e->getTraceAsString());
        $_SESSION['pvewhmcs_custom_args_message'] = "Error: " . htmlentities($e->getMessage());
    }

    if (function_exists('generate_token')) { $_SESSION['tkval'] = generate_token('plain'); }
    header("Location: clientarea.php?action=productdetails&id=" . $serviceid . "&modop=custom&a=loadCustomArgsPage");
    exit;
}

// ACTION: Save Boot Order Configuration by Client
function pvewhmcs_saveBootOrderConfig($params) {
    session_start(); // Required to pass messages back via session
    $serviceid = $params['serviceid'];

    // Verify CSRF token
    if (function_exists('check_token')) {
        check_token("WHMCS.default");
    } else {
        // Fallback CSRF check if check_token is somehow unavailable in this context
        $submitted_token = isset($_POST['token']) ? $_POST['token'] : '';
        if (!isset($_SESSION['tkval']) || empty($_SESSION['tkval']) || $submitted_token !== $_SESSION['tkval']) {
            if (function_exists('generate_token')) { $_SESSION['tkval'] = generate_token('plain'); } // Regenerate for next attempt
            $_SESSION['pvewhmcs_boot_order_message'] = "Error: Security token validation failed. Please try again.";
            header("Location: clientarea.php?action=productdetails&id=" . $serviceid . "&modop=custom&a=loadBootOrderPage");
            exit;
        }
        // If manual check passes, regenerate token for next form load
        if (function_exists('generate_token')) {
            $_SESSION['tkval'] = generate_token('plain');
        }
    }

    // Retrieve the order of all devices as submitted by the sortable list
    $ordered_devices_all = isset($_POST['boot_device_order']) ? $_POST['boot_device_order'] : [];
    // Retrieve only the devices that were checked (enabled)
    $enabled_devices = isset($_POST['boot_device_enabled']) ? $_POST['boot_device_enabled'] : [];

    $final_boot_order_parts = [];
    // Iterate through the submitted order of all devices
    foreach ($ordered_devices_all as $device_in_order) {
        // If this device was also checked (enabled), add it to the final list in that order
        if (in_array($device_in_order, $enabled_devices)) {
            $final_boot_order_parts[] = $device_in_order;
        }
    }

    $boot_config_string = "";
    if (!empty($final_boot_order_parts)) {
        $boot_config_string = "order=" . implode(';', $final_boot_order_parts);
    } else {
        // If no devices are selected, the user must select at least one.
        // Proxmox requires a boot device. Sending an empty 'order=' or empty 'boot' is problematic.
        $_SESSION['pvewhmcs_boot_order_message'] = "Error: You must select at least one boot device.";
        // Regenerate token before redirecting due to error (as check_token might have consumed it)
        if (function_exists('generate_token')) { $_SESSION['tkval'] = generate_token('plain'); }
        header("Location: clientarea.php?action=productdetails&id=" . $serviceid . "&modop=custom&a=loadBootOrderPage");
        exit;
    }

    logModuleCall('pvewhmcs', __FUNCTION__ . ' - Boot Order String to be set', $boot_config_string, $_POST);

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
        $vtype = $guest_db_info->vtype;

        if ($vtype !== 'qemu') {
            throw new Exception("Boot order configuration is only applicable to QEMU/KVM virtual machines.");
        }

        $config_params = ['boot' => $boot_config_string];

        logModuleCall('pvewhmcs', __FUNCTION__ . ' - API Config Params', $config_params, '');

        $update_response = $proxmox->put("/nodes/{$first_node}/{$vtype}/{$serviceid}/config", $config_params);
        logModuleCall('pvewhmcs', __FUNCTION__ . ' (update_config response)', "/nodes/{$first_node}/{$vtype}/{$serviceid}/config", $update_response);

        // The PVE2_API class's put method returns true on HTTP 200, or throws an exception/returns false on error.
        if ($update_response !== true) {
             $api_error_message = "Failed to update boot order on Proxmox.";
             // Attempt to get more details if the response was an array (often contains error info from API)
             if(is_array($update_response) && isset($update_response['errors'])) {
                 $api_error_message .= " Details: " . json_encode($update_response['errors']);
             } else if (is_string($update_response) && !empty($update_response)) {
                 // Sometimes the API might return a string error directly in $update_response if not a structured JSON error
                 $api_error_message .= " Details: " . htmlentities($update_response);
             } else if ($update_response === false) {
                $api_error_message .= " The API call returned false.";
             }
            throw new Exception($api_error_message);
        }

        $_SESSION['pvewhmcs_boot_order_message'] = "Success: Boot order updated to '{$boot_config_string}'. A reboot may be required for changes to take effect on the next startup.";

    } catch (Exception $e) {
        logModuleCall('pvewhmcs', __FUNCTION__ . ' - Exception Caught', $boot_config_string, $e->getMessage() . $e->getTraceAsString());
        $_SESSION['pvewhmcs_boot_order_message'] = "Error: " . htmlentities($e->getMessage());
    }

    // Regenerate token for the next form load on the page we are redirecting to.
    if (function_exists('generate_token')) {
        $_SESSION['tkval'] = generate_token('plain');
    }

    header("Location: clientarea.php?action=productdetails&id=" . $serviceid . "&modop=custom&a=loadBootOrderPage");
    exit;
}

// ACTION: Display Custom QEMU Arguments Page
function pvewhmcs_loadCustomArgsPage($params) {
    // Gather access credentials for PVE
    $pveservice = Capsule::table('tblhosting')->find($params['serviceid']);
    $pveserver = Capsule::table('tblservers')->where('id', '=', $pveservice->server)->first();

    $serverip = $pveserver->ipaddress;
    $serverusername = $pveserver->username;
    $api_data = array('password2' => $pveserver->password);
    $serverpassword_decrypted = localAPI('DecryptPassword', $api_data);
    $serverpassword = $serverpassword_decrypted['password'];

    $proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword);

    $current_custom_args = '';
    $error_message = null;
    $action_message = null;

    if (isset($_SESSION['pvewhmcs_custom_args_message'])) {
        $action_message = $_SESSION['pvewhmcs_custom_args_message'];
        unset($_SESSION['pvewhmcs_custom_args_message']);
    }

    $guest_info = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->first();
    if (!$guest_info) {
        $error_message = "VM details not found in module database.";
    } else {
        $vm_type = $guest_info->vtype;
        if ($vm_type !== 'qemu') {
            $error_message = "Advanced boot options (custom QEMU arguments) are only applicable to QEMU/KVM virtual machines.";
        } else {
            // Fetch current args from local DB first, as Proxmox API might not always return them if they are complex or very long via standard config GET.
            // Or, it's better to rely on what we have stored as the source of truth for this field.
            $current_custom_args = $guest_info->custom_args ?: '';
        }
    }

    // If we wanted to double check with Proxmox (optional, local DB should be source of truth for this specific field)
    // if (!$error_message && $proxmox->login()) {
    //     $nodes = $proxmox->get_node_list();
    //     if (!empty($nodes) && isset($nodes[0])) {
    //         $first_node = $nodes[0];
    //         $vm_config = $proxmox->get("/nodes/{$first_node}/qemu/{$params['serviceid']}/config");
    //         if ($vm_config && isset($vm_config['args'])) {
    //             // This could be used to compare or show Proxmox's current view, but might be complex.
    //             // For simplicity, we'll primarily rely on our DB stored value.
    //         }
    //     }
    // } elseif (!$error_message) {
    //     $error_message = "Failed to login to Proxmox API to verify current settings (displaying stored value).";
    // }

    $csrf_token = '';
    if (function_exists('generate_token')) {
        $csrf_token = generate_token('plain');
    }

    return array(
        'templatefile' => 'load_custom_args_area',
        'vars' => array(
            'params' => $params,
            'current_custom_args' => $current_custom_args,
            'error_message' => $error_message,
            'action_message' => $action_message,
            'csrf_token' => $csrf_token,
        ),
    );
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
        //$reboot_params = []; // No specific params needed for reboot usually
        //logModuleCall('pvewhmcs', __FUNCTION__ . ' - Before Reboot API call', "/nodes/{$first_node}/{$vtype}/{$serviceid}/status/reboot", '');
        //$reboot_response = $proxmox->post("/nodes/{$first_node}/{$vtype}/{$serviceid}/status/reboot", $reboot_params);
        //logModuleCall('pvewhmcs', __FUNCTION__ . ' (reboot_vm) - API Response', $reboot_response, '');

        //if (isset($reboot_response['errors'])) {
        //    // Log reboot error but proceed with success message for config change
        //    logModuleCall('pvewhmcs', __FUNCTION__ . ' (reboot_vm_error)', "Error rebooting VM: " . json_encode($reboot_response['errors']), '');
        //}
        // if (strpos((string)$reboot_response, 'UPID:') !== 0 && !empty($reboot_response)) {
        //    if(is_array($reboot_response) && !empty($reboot_response['data'])){
        //        // Potentially successful reboot task started
        //         logModuleCall('pvewhmcs', __FUNCTION__ . ' (reboot_vm_task_started_data)', $reboot_response['data'], '');
        //    } else if (empty($reboot_response)){
        //        // Empty response can be success for reboot task start
        //         logModuleCall('pvewhmcs', __FUNCTION__ . ' (reboot_vm_task_started_empty_response)', '', '');
        //    }
        //    else {
        //         logModuleCall('pvewhmcs', __FUNCTION__ . ' (reboot_vm_fail)', "Failed to initiate VM reboot. Unexpected response: " . json_encode($reboot_response), '');
        //    }
        //}

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

function pvewhmcs_kernelconfig($params) {
	return $params;
}



// Function to handle the ISO loading page
function pvewhmcs_loadIsoPage($params) {
    error_log('loadIsoPage page Triggered.');
    // Gather access credentials for PVE
    $pveservice = Capsule::table('tblhosting')->find($params['serviceid']);
    $pveserver = Capsule::table('tblservers')->where('id', '=', $pveservice->server)->get()[0];

    $serverip = $pveserver->ipaddress;
    $serverusername = $pveserver->username;
    $api_data = array('password2' => $pveserver->password);
    $serverpassword_decrypted = localAPI('DecryptPassword', $api_data);
    $serverpassword = $serverpassword_decrypted['password'];

    $proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword);

    $iso_images = array();
    $current_iso = null;
    $current_drive = null;
    $error_message = null;
    $first_node = null; // Initialize first_node

    if ($proxmox->login()) {
        $nodes = $proxmox->get_node_list();
        if (!empty($nodes) && isset($nodes[0])) {
            $first_node = $nodes[0];
            $guest_info = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->get()[0];
            $vm_type = $guest_info->vtype; // qemu or lxc

            // ISO operations are typically for QEMU/KVM
            if ($vm_type == 'qemu') {
                // Define ISO storage - ideally this should be configurable
                $iso_storage = 'local'; // Hardcoded for now, as per plan

                $iso_images = $proxmox->get_iso_images($first_node, $iso_storage);

                // Get current VM config to check for mounted ISO
                $vm_config = $proxmox->get("/nodes/{$first_node}/{$vm_type}/{$params['serviceid']}/config");
                if ($vm_config) {
                    // Check common drives for an ISO
                    $possible_drives = ['ide0', 'ide1', 'ide2', 'ide3', 'sata0', 'sata1', 'sata2', 'sata3', 'sata4', 'sata5'];
                    foreach ($possible_drives as $drive) {
                        if (isset($vm_config[$drive]) && strpos($vm_config[$drive], ',media=cdrom') !== false) {
                            // Example: local:iso/imagename.iso,media=cdrom,size=123M
                            preg_match('/iso\/(.*?)(,|$)/', $vm_config[$drive], $matches);
                            if (isset($matches[1])) {
                                $current_iso = $matches[1];
                                $current_drive = $drive;
                                break;
                            }
                        }
                    }
                } else {
                    $error_message = "Could not retrieve VM configuration.";
                }
            } else {
                $error_message = "ISO mounting is not supported for LXC containers.";
            }
        } else {
            $error_message = "Could not retrieve node list from Proxmox.";
        }
    } else {
        $error_message = "Failed to login to Proxmox API. Please check credentials and connectivity.";
    }
    
    // Handle messages from mount/unmount operations
    if (isset($_SESSION['pvewhmcs_iso_message'])) {
        $action_message = $_SESSION['pvewhmcs_iso_message'];
        unset($_SESSION['pvewhmcs_iso_message']);
    } else {
        $action_message = null;
    }

    return array(
        'templatefile' => 'load_iso_area', // New template file
        'vars' => array(
            'params' => $params, // Pass WHMCS params
            'iso_images' => $iso_images,
            'current_iso' => $current_iso,
            'current_drive' => $current_drive,
            'iso_storage_assumed' => 'local', // Inform template about assumed storage
            'error_message' => $error_message,
            'action_message' => $action_message, // For success/error from mount/unmount
            'first_node' => $first_node, // Pass node for form submission if needed
        ),
    );
}


// Function to mount an ISO
function pvewhmcs_mountIso($params) {
    session_start(); // Required to pass messages back via session
    $iso_image = isset($_REQUEST['iso_image']) ? trim($_REQUEST['iso_image']) : null;
    // Use a default drive, or allow selection. For now, assume 'ide2' if not specified or make it part of the form.
    $drive_to_use = isset($_REQUEST['drive_to_use']) && !empty($_REQUEST['drive_to_use']) ? trim($_REQUEST['drive_to_use']) : 'ide2'; 
    // Assume storage is 'local' or get from form if made configurable there
    $storage_location = isset($_REQUEST['storage_location']) && !empty($_REQUEST['storage_location']) ? trim($_REQUEST['storage_location']) : 'local';

    if (empty($iso_image)) {
        $_SESSION['pvewhmcs_iso_message'] = "Error: No ISO image selected.";
        header("Location: clientarea.php?action=productdetails&id={$params['serviceid']}&modop=custom&a=loadIsoPage");
        exit;
    }

    // Gather access credentials
    $pveservice = Capsule::table('tblhosting')->find($params['serviceid']);
    $pveserver = Capsule::table('tblservers')->where('id', '=', $pveservice->server)->get()[0];
    $serverip = $pveserver->ipaddress;
    $serverusername = $pveserver->username;
    $api_data = array('password2' => $pveserver->password);
    $serverpassword_decrypted = localAPI('DecryptPassword', $api_data);
    $serverpassword = $serverpassword_decrypted['password'];

    $proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword);

    if ($proxmox->login()) {
        $nodes = $proxmox->get_node_list();
        if (!empty($nodes) && isset($nodes[0])) {
            $first_node = $nodes[0];
            $guest_info = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->get()[0];
            $vm_type = $guest_info->vtype;

            if ($vm_type == 'qemu') {
                if ($proxmox->mount_iso_image($first_node, $params['serviceid'], $iso_image, $drive_to_use, $storage_location)) {
                    $iso_mount_success_message = "Success: ISO '{$iso_image}' mounted to {$drive_to_use}.";
                    $force_bios_message = '';
                    $boot_order_message = '';
                    $reboot_message = '';

                    // Check if force BIOS on reboot is requested
                    if (isset($_REQUEST['force_bios_on_reboot']) && $_REQUEST['force_bios_on_reboot'] == '1') {
                        // Parameter might be 'forcebios' => 1 for SeaBIOS or 'efisetup' => 1 for OVMF (UEFI)
                        // For UEFI VMs, 'efisetup: 1' should make it boot into UEFI setup once.
                        // For SeaBIOS, this parameter will likely be ignored or cause an error if not in schema for SeaBIOS.
                        // The error "property is not defined in schema" would occur if 'efisetup' is not valid for the VM's current config (e.g. SeaBIOS).
                        $force_bios_params = array('efisetup' => 1); 
                        try {
                            if ($proxmox->put("/nodes/{$first_node}/qemu/{$params['serviceid']}/config", $force_bios_params)) {
                                $force_bios_message = " VM set to attempt entry into UEFI setup on next boot.";
                                if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
                                    logModuleCall('pvewhmcs', __FUNCTION__, "Successfully sent efisetup=1 for VM {$params['serviceid']}", $force_bios_params);
                                }
                            } else {
                                $force_bios_message = " Attempt to set UEFI setup flag failed (API returned false). This may be normal for SeaBIOS VMs.";
                                if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
                                    logModuleCall('pvewhmcs', __FUNCTION__, "Failed to set efisetup=1 for VM {$params['serviceid']} (API returned false)", $force_bios_params);
                                }
                            }
                        } catch (PVE2_Exception $e) {
                            // Catching the specific error about parameter not defined in schema
                            if (strpos($e->getMessage(), "property is not defined in schema") !== false) {
                                $force_bios_message = " Note: Could not set UEFI setup flag (efisetup=1), this VM might not be using UEFI or the Proxmox version doesn't support this for its current configuration.";
                                 if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
                                    logModuleCall('pvewhmcs', __FUNCTION__, "Attempt to set efisetup=1 failed for VM {$params['serviceid']} - likely not a UEFI VM or parameter not applicable.", $e->getMessage());
                                }
                            } else {
                                $force_bios_message = " Attempt to set UEFI setup flag failed with API exception: " . htmlentities($e->getMessage());
                                if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
                                    logModuleCall('pvewhmcs', __FUNCTION__, "Exception while setting efisetup=1 for VM {$params['serviceid']}", $e->getMessage());
                                }
                            }
                        }
                    }

                    // Check if force boot from ISO is requested
                    // This should ideally happen *after* force BIOS if both are set,
                    // but BIOS entry usually takes precedence anyway.
                    // Or, if BIOS is forced, setting boot order might be redundant for that one boot.
                    // For simplicity, we'll apply both if checked.
                    if (isset($_REQUEST['force_boot_from_iso']) && $_REQUEST['force_boot_from_iso'] == '1') {
                        $boot_order_set_params = array('boot' => "order={$drive_to_use}");
                        try {
                            if ($proxmox->put("/nodes/{$first_node}/qemu/{$params['serviceid']}/config", $boot_order_set_params)) {
                                $boot_order_message = " Boot order set to prioritize {$drive_to_use} for next boot.";
                                // Note: If forcebios was also set, this boot order might be overridden by entering BIOS setup first.
                            } else {
                                $boot_order_message = " Attempt to set boot order to {$drive_to_use} failed (API returned false).";
                            }
                        } catch (PVE2_Exception $e) {
                            $boot_order_message = " Attempt to set boot order to {$drive_to_use} failed with API exception: " . htmlentities($e->getMessage());
                        }
                         if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1 && !empty($boot_order_message) ) {
                             logModuleCall('pvewhmcs', __FUNCTION__, "Boot order setting attempt for VM {$params['serviceid']}:" . $boot_order_message, $boot_order_set_params ?? []);
                         }
                    }

                    // Attempt reboot
                    //$reboot_status = pvewhmcs_vmReboot($params); // Call the enhanced reboot function
                    //if ($reboot_status === "success") {
                    //    $reboot_message = " VM reboot initiated successfully.";
                    //} else {
                    //    $reboot_message = " However, the subsequent VM reboot attempt failed: " . htmlentities($reboot_status);
                    //}
                    $_SESSION['pvewhmcs_iso_message'] = $iso_mount_success_message . $force_bios_message . $boot_order_message . $reboot_message;

                } else {
                    $_SESSION['pvewhmcs_iso_message'] = "Error: Failed to mount ISO '{$iso_image}'. Check module logs.";
                }
            } else {
                $_SESSION['pvewhmcs_iso_message'] = "Error: ISO mounting not supported for this VM type.";
            }
        } else {
             $_SESSION['pvewhmcs_iso_message'] = "Error: Could not retrieve node list from Proxmox.";
        }
    } else {
        $_SESSION['pvewhmcs_iso_message'] = "Error: Failed to login to Proxmox API.";
    }
    
    header("Location: clientarea.php?action=productdetails&id={$params['serviceid']}&modop=custom&a=loadIsoPage");
    exit;
}


// Function to unmount/eject an ISO
function pvewhmcs_unmountIso($params) {
    session_start(); // Required to pass messages back via session
    // Drive to unmount should be passed, or determined from current config
    $drive_to_unmount = isset($_REQUEST['drive_to_unmount']) && !empty($_REQUEST['drive_to_unmount']) ? trim($_REQUEST['drive_to_unmount']) : 'ide2';


    // Gather access credentials
    $pveservice = Capsule::table('tblhosting')->find($params['serviceid']);
    $pveserver = Capsule::table('tblservers')->where('id', '=', $pveservice->server)->get()[0];
    $serverip = $pveserver->ipaddress;
    $serverusername = $pveserver->username;
    $api_data = array('password2' => $pveserver->password);
    $serverpassword_decrypted = localAPI('DecryptPassword', $api_data);
    $serverpassword = $serverpassword_decrypted['password'];

    $proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword);

    if ($proxmox->login()) {
        $nodes = $proxmox->get_node_list();
        if (!empty($nodes) && isset($nodes[0])) {
            $first_node = $nodes[0];
            $guest_info = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->get()[0];
            $vm_type = $guest_info->vtype;

            if ($vm_type == 'qemu') {
                if ($proxmox->unmount_iso_image($first_node, $params['serviceid'], $drive_to_unmount)) {
                    $_SESSION['pvewhmcs_iso_message'] = "Success: ISO ejected from {$drive_to_unmount}.";
                } else {
                    $_SESSION['pvewhmcs_iso_message'] = "Error: Failed to eject ISO from {$drive_to_unmount}. Check module logs.";
                }
            } else {
                $_SESSION['pvewhmcs_iso_message'] = "Error: ISO operations not supported for this VM type.";
            }
        } else {
            $_SESSION['pvewhmcs_iso_message'] = "Error: Could not retrieve node list from Proxmox.";
        }
    } else {
        $_SESSION['pvewhmcs_iso_message'] = "Error: Failed to login to Proxmox API.";
    }

    header("Location: clientarea.php?action=productdetails&id={$params['serviceid']}&modop=custom&a=loadIsoPage");
    exit;
}


function pvewhmcs_noVNC($params) {
    // Define a debug log file
    //$debugLog = '/tmp/pvewhmcs_novnc_debug.log';
    //file_put_contents($debugLog, "=== noVNC Debug Start ===\n", FILE_APPEND);

     // Check if VNC Secret is configured
     $vncSecret = Capsule::table('mod_pvewhmcs')->where('id', '1')->value('vnc_secret');
     if (strlen($vncSecret) < 15) {
	        throw new Exception("PVEWHMCS Error: VNC Secret in Module Config either not set or not long enough.");
     }



    $serverip = $params["serverip"];
    $serverusername = 'vnc';
    $serverpassword = $vncSecret;


    $proxmox = new PVE2_API($serverip, $serverusername, "pve", $serverpassword);

    if ($proxmox->login()) {
	    // Do all your API calls first
	    $nodes = $proxmox->get_node_list();
	    $first_node = $nodes[0];
	
	    $guest = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->get()[0];
	    $pveticket = $proxmox->getTicket();

		try {
		    $vm_vncproxy = $proxmox->post(
		        '/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/vncproxy',
		        ['websocket' => '1']
		    );

		    //$vm_vncproxy = $proxmox->post('/nodes/pve1/qemu/101/vncproxy', ['websocket' => '1']);
		    error_log("✅ vncproxy response: " . print_r($vm_vncproxy, true));
		} catch (Exception $e) {
		    error_log("❌ vncproxy failed: " . $e->getMessage());
		}



	//    error_log(sprintf('%s', print_r($vm_vncproxy)));

	    // ✅ Now get the ticket — it will match the session used for vncproxy
	    $vncticket = $vm_vncproxy['ticket'];
	
	    // Debug session IDs
	    preg_match('/:([A-Za-z0-9]+)::/', $pveticket, $pveMatch);
	    preg_match('/:([A-Za-z0-9]+)::/', $vncticket, $vncMatch);
	    $pveSession = $pveMatch[1] ?? 'N/A';
	    $vncSession = $vncMatch[1] ?? 'N/A';

	  if ($pveSession !== $vncSession) {
	        error_log("⚠️ Session ID mismatch: PVE=$pveSession, VNC=$vncSession");
	        return 'Failed to prepare noVNC. Please reload and try again.';
	  } else {
		    error_log("✅ ticketid: " . $vncticket);
	  }



        $path = 'api2/json/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/vncwebsocket?port=' . $vm_vncproxy['port'] . '&vncticket=' . urlencode($vncticket);
        $url = '/modules/servers/pvewhmcs/novnc_router.php?host=' . $serverip . '&pveticket=' . urlencode($pveticket) . '&path=' . urlencode($path) . '&vncticket=' . urlencode($vncticket);


        //return '<center><strong>Console (noVNC) prepared for usage. <a href="' . $url . '" target="_blank">Click here</a> to open the noVNC window.</strong></center>';
	return sprintf(<<<HTML
		<center><strong>Console (noVNC) prepared for usage in a new window. Disable your popup blocker. 
		  <script>
		    window.onload = function() {
			window.open('%s', 'noVNCWindow', 'width=1280,height=1024,toolbar=no,menubar=no,scrollbars=no,resizable=yes,location=no,status=no'); return false;
		    };
		  </script>
		HTML, $url);

        //header(sprintf("Location: %s", %s));
	//return True;
    } else {
        return 'Failed to prepare noVNC. Please contact Technical Support.';
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
        error_log("pvewhmcs_vmStart() was called for service ID: " . $params['serviceid']);
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
//	if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
		logModuleCall(
			'pvewhmcs',
			__FUNCTION__,
			$logrequest,
			json_encode($response)
		);
//	}


	error_log("Proxmox vmStart response: " . print_r($response, true));


	if (isset($response) && !isset($response['errors'])) {
		    return ['success' => true];
	} else {
		    $response_message = isset($response['errors']) ? json_encode($response['errors']) : json_encode($response);
		    return ['error' => "Proxmox API Error: " . $response_message];
	}

}


// Define constants for task polling if not already defined elsewhere
if (!defined('PVEWHMCS_MAX_TASK_RETRIES')) {
    define('PVEWHMCS_MAX_TASK_RETRIES', 12); // 12 retries * 5 seconds = 60 seconds timeout
}
if (!defined('PVEWHMCS_TASK_RETRY_INTERVAL')) {
    define('PVEWHMCS_TASK_RETRY_INTERVAL', 5); // 5 seconds
}



 function pvewhmcs_vmReboot2($params) {
    $action_result = "Could not connect to hypervisor or gather required information."; // Default error

    try {
        // Gather access credentials for PVE
        $pveservice = Capsule::table('tblhosting')->find($params['serviceid']);
        if (!$pveservice) throw new Exception("Service hosting record not found.");
        $pveserver = Capsule::table('tblservers')->where('id', '=', $pveservice->server)->first();
        if (!$pveserver) throw new Exception("Server record not found for service.");

        $serverip = $pveserver->ipaddress;
        $serverusername = $pveserver->username;
        $api_data = array('password2' => $pveserver->password);
        $decrypted_password = localAPI('DecryptPassword', $api_data);
        $serverpassword = $decrypted_password['password'];

        $proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword);

        if (!$proxmox->login()) {
            throw new Exception("Proxmox API login failed. Please check credentials.");
        }

        $nodes = $proxmox->get_node_list();
        if (empty($nodes) || !isset($nodes[0])) {
            throw new Exception("Could not retrieve node list from Proxmox.");
        }
        $first_node = $nodes[0];

        $guest = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->first();
        if (!$guest) {
            throw new Exception("VM details not found in module database.");
        }

        $pve_cmdparam = array();
        $api_endpoint_to_call = '';
        $log_action_description = '';

        $guest_specific_status = $proxmox->get('/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/current');

        if (isset($guest_specific_status['status']) && $guest_specific_status['status'] == 'stopped') {
            $api_endpoint_to_call = '/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/start';
            $log_action_description = 'Attempting to START stopped VM as part of reboot request.';
        } else {
            // Assumes if not stopped, it's running or in a state where reboot is appropriate
            $api_endpoint_to_call = '/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/reboot';
            $log_action_description = 'Attempting to REBOOT VM.';
        }
        
        if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
            logModuleCall('pvewhmcs', __FUNCTION__, $log_action_description . ' Endpoint: ' . $api_endpoint_to_call, $pve_cmdparam);
        }

        $raw_response = $proxmox->post($api_endpoint_to_call, $pve_cmdparam);

        if (is_string($raw_response) && strpos($raw_response, 'UPID:') === 0) {
            $upid = trim($raw_response);
            $action_result = "Operation initiated (UPID: {$upid}), awaiting completion...";
            $task_completed_successfully = false;

            for ($i = 0; $i < PVEWHMCS_MAX_TASK_RETRIES; $i++) {
                sleep(PVEWHMCS_TASK_RETRY_INTERVAL);
                $task_status = $proxmox->get("/nodes/{$first_node}/tasks/{$upid}/status");

                if (isset($task_status['status']) && $task_status['status'] === 'stopped') {
                    if (isset($task_status['exitstatus']) && $task_status['exitstatus'] === 'OK') {
                        $action_result = "success";
                        $task_completed_successfully = true;
                        break;
                    } else {
                        $error_detail = $task_status['exitstatus'] ?? 'Unknown task error';
                        if (strpos($error_detail, "can't lock file") !== false || strpos($error_detail, "got timeout") !== false) {
                            $action_result = "Error: Proxmox could not acquire lock for the operation. The VM might be busy (e.g., backup) or another task is active. Please try again later. (Details: {$error_detail})";
                        } else {
                            $action_result = "Error: Proxmox task failed. (Details: {$error_detail})";
                        }
                        $task_completed_successfully = true; // Task stopped, even if failed
                        break;
                    }
                } else if (!isset($task_status['status'])) {
                     $action_result = "Warning: Could not retrieve task status update for UPID: {$upid}. Assuming operation is still in progress or completed elsewhere.";
                     // This is ambiguous. For safety, we might not want to declare success.
                     // Depending on strictness, could break or log and continue retrying.
                     // For now, we'll let it retry.
                }
                // If status is 'running', loop continues.
            }

            if (!$task_completed_successfully) {
                 // If loop finished without task stopping or explicit success
                $action_result = "Operation timed out waiting for Proxmox task (UPID: {$upid}) to complete after " . (PVEWHMCS_MAX_TASK_RETRIES * PVEWHMCS_TASK_RETRY_INTERVAL) . " seconds.";
            }

        } elseif (is_array($raw_response) && isset($raw_response['data']) && $raw_response['data'] === null && !isset($raw_response['errors'])) {
            // Some simple commands might return { "data": null } on success immediately without a UPID
            // For reboot/start, a UPID is generally expected. This might indicate an issue or a very fast completion.
            // For now, we treat it as potential success if no errors.
             $action_result = "success"; // Or needs more specific handling based on Proxmox behavior for this exact call.
             if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
                logModuleCall('pvewhmcs', __FUNCTION__, $api_endpoint_to_call . ' received non-UPID success response', $raw_response);
            }
        } elseif (is_array($raw_response) && isset($raw_response['errors'])) {
            $action_result = "Error performing action: " . json_encode($raw_response['errors']);
        } else {
            $action_result = "Unexpected response from Proxmox API: " . (is_scalar($raw_response) ? $raw_response : json_encode($raw_response));
        }

    } catch (PVE2_Exception $e) {
        $action_result = "Proxmox API Communication Error: " . $e->getMessage();
    } catch (Exception $e) {
        $action_result = "Error: " . $e->getMessage();
    }

    if ($action_result !== "success" && Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
        logModuleCall(
            'pvewhmcs',
            __FUNCTION__,
            isset($api_endpoint_to_call) ? $api_endpoint_to_call : 'N/A',
            $action_result // Log the detailed error message
        );
    }
    return $action_result;
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


        	if ($guest_specific['status'] == 'stopped') {
			// START if Stopped
			$logrequest = '/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/start';
			$response = $proxmox->post('/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/start' , $pve_cmdparam);
		} else {
			// REBOOT if Started

			$logrequest = '/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/stop';
			$response = $proxmox->post('/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/stop' , $pve_cmdparam);

			$logrequest = '/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/start';
			$response = $proxmox->post('/nodes/' . $first_node . '/' . $guest->vtype . '/' . $params['serviceid'] . '/status/start' , $pve_cmdparam);

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

//	throw new Exception(sprintf(':: %s', json_encode($guest_specific['status'])));


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
