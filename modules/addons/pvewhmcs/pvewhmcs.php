<?php

/*  
	Proxmox VE for WHMCS - Addon/Server Modules for WHMCS (& PVE)
	https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/
	File: /modules/addons/pvewhmcs/pvewhmcs.php (GUI Work)

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

// Pull in the WHMCS database handler Capsule for SQL
use Illuminate\Database\Capsule\Manager as Capsule;

// Define where the module operates in the Admin GUI
define( 'pvewhmcs_BASEURL', 'addonmodules.php?module=pvewhmcs' );

// DEP: Require the PHP API Class to interact with Proxmox VE
require_once('proxmox.php');

// CONFIG: Declare key options to the WHMCS Addon Module framework.
function pvewhmcs_config() {
	$configarray = array(
		"name" => "Proxmox VE for WHMCS",
		"description" => "Proxmox VE (Virtual Environment) & WHMCS, integrated & open-source! Provisioning & Management of VMs/CTs.".is_pvewhmcs_outdated(),
		"version" => "1.2.8",
		"author" => "The Network Crew Pty Ltd",
		'language' => 'English'
	);
	return $configarray;
}

// VERSION: also stored in repo/version (for update-available checker)
function pvewhmcs_version(){
    return "1.2.8";
}

// WHMCS MODULE: ACTIVATION of the ADDON MODULE
// This consists of importing the SQL structure, and then crudely returning yay or nay (needs improving)
function pvewhmcs_activate() {
	// Pull in the SQL structure (includes VNC/etc tweaks)
	$sql = file_get_contents(__DIR__ . '/db.sql');
	if (!$sql) {
		return array('status'=>'error','description'=>'The db.sql file was not found.');
	}
	// SQL file is good, let's proceed with pulling it in
	$err=false;
	$i=0;
	$query_array=explode(';',$sql) ;
	$query_count=count($query_array) ;
	// Iterate through the SQL commands to finalise init.
	foreach ( $query_array as $query) {
		if ($i<$query_count-1) {
            $trimmedQuery = trim($query);
            if (!empty($trimmedQuery)) { // Ensure query is not empty
                if (!Capsule::statement($trimmedQuery . ';')) {
                    $err = true;
                }
            }
        }
		$i++ ;
	}

    // After initial setup from db.sql, check and add pool_type column if it doesn't exist
    if (!$err) {
        try {
            $dbName = Capsule::connection()->getDatabaseName();
            $columnCheck = Capsule::select(
                "SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = 'mod_pvewhmcs_ip_pools' AND column_name = 'pool_type'",
                [$dbName]
            );
            if (empty($columnCheck)) {
                Capsule::statement("ALTER TABLE mod_pvewhmcs_ip_pools ADD COLUMN pool_type VARCHAR(4) NOT NULL DEFAULT 'ipv4'");
            }
        } catch (\Exception $e) {
            // Log error or handle. This prevents fatal errors on re-activation or if permissions are an issue.
            // For WHMCS, you might use logActivity() if available in this context, or a custom logger.
            // For simplicity here, we'll note it might cause an error that's suppressed.
            // In a real scenario, logging this is crucial.
            $err = true; // Mark as error if this fails, as schema might be inconsistent.
            $activation_message_description = 'Proxmox VE for WHMCS was partially installed. Error adding pool_type column: ' . $e->getMessage();
        }
    }

	// Return success or error.
	if (!$err) {
		return array('status'=>'success','description'=> isset($activation_message_description) ? $activation_message_description : 'Proxmox VE for WHMCS was installed successfully!');
    } else {
	    return array('status'=>'error','description'=> isset($activation_message_description) ? $activation_message_description : 'Proxmox VE for WHMCS was not activated properly. Some SQL statements may have failed.');
    }
}

// WHMCS MODULE: DEACTIVATION
function pvewhmcs_deactivate() {
	// Drop all module-related tables
	Capsule::statement('drop table mod_pvewhmcs_ip_addresses,mod_pvewhmcs_ip_pools,mod_pvewhmcs_plans,mod_pvewhmcs_vms,mod_pvewhmcs');
	// Return the assumed result (change?)
	return array('status'=>'success','description'=>'Proxmox VE for WHMCS successfully deactivated and all related tables deleted.');
}

// UPDATE CHECKER: live vs repo
function is_pvewhmcs_outdated(){
    if(get_pvewhmcs_latest_version() > pvewhmcs_version()){
        return "<br><span style='float:right;'><b>Proxmox VE for WHMCS is outdated: <a style='color:red' href='https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/releases'>Download the new version!</a></span>";
    }
}

// UPDATE CHECKER: return latest ver
function get_pvewhmcs_latest_version(){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://raw.githubusercontent.com/The-Network-Crew/Proxmox-VE-for-WHMCS/master/version");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close ($ch);

    return str_replace("\n", "", $result);
}

// ADMIN MODULE GUI: output (HTML etc)
function pvewhmcs_output($vars) {
	$modulelink = $vars['modulelink'];

	// Check for update and report if available
	if (!empty(is_pvewhmcs_outdated())) {
		$_SESSION['pvewhmcs']['infomsg']['title']='Proxmox VE for WHMCS: New version available!' ;
		$_SESSION['pvewhmcs']['infomsg']['message']='Please visit the GitHub repository > Releases page. https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/releases' ;
	}
		
	// Print Messages to GUI before anything else
	if (isset($_SESSION['pvewhmcs']['infomsg'])) {
		echo '
		<div class="infobox">
		<strong>
		<span class="title">'.$_SESSION['pvewhmcs']['infomsg']['title'].'</span>
		</strong><br/>
		'.$_SESSION['pvewhmcs']['infomsg']['message'].'
		</div>
		' ;
		unset($_SESSION['pvewhmcs']) ;
	}

	echo '
	<div id="clienttabs">
	<ul class="nav nav-tabs admin-tabs">
	<li class="'.($_GET['tab']=="vmplans" ? "active" : "").'"><a id="tabLink1" data-toggle="tab" role="tab" href="#plans">VM/CT Plans</a></li>
	<li class="'.(preg_match("/^ippools/", $_GET['tab']) ? "active" : "").'"><a id="tabLink2" data-toggle="tab" role="tab" href="#ippools">IP Pools</a></li>
	<li class="'.($_GET['tab']=="nodes" ? "active" : "").'"><a id="tabLink3" data-toggle="tab" role="tab" href="#nodes">Nodes / Cluster</a></li>
	<li class="'.($_GET['tab']=="actions" ? "active" : "").'"><a id="tabLink4" data-toggle="tab" role="tab" href="#actions">Actions / Logs</a></li>
	<li class="'.($_GET['tab']=="health" ? "active" : "").'"><a id="tabLink5" data-toggle="tab" role="tab" href="#health">Support / Health</a></li>
	<li class="'.($_GET['tab']=="config" ? "active" : "").'"><a id="tabLink6" data-toggle="tab" role="tab" href="#config">Module Config</a></li>
	</ul>
	</div>
	<div class="tab-content admin-tabs">
	' ;


	if (isset($_POST['addnewkvmplan']))
	{
		save_kvm_plan() ;
	}

	if (isset($_POST['updatekvmplan']))
	{
		update_kvm_plan() ;
	}
	if (isset($_POST['updatelxcplan']))
	{
		update_lxc_plan() ;
	}

	if (isset($_POST['addnewlxcplan']))
	{
		save_lxc_plan() ;
	}

	echo '
	<div id="plans" class="tab-pane '.($_GET['tab']=="vmplans" ? "active" : "").'">
	<div class="btn-group" role="group" aria-label="...">
	<a class="btn btn-default" href="'. pvewhmcs_BASEURL .'&amp;tab=vmplans&amp;action=planlist">
	<i class="fa fa-list"></i>&nbsp; List: Guest Plans
	</a>
	<a class="btn btn-default" href="'. pvewhmcs_BASEURL .'&amp;tab=vmplans&amp;action=add_kvm_plan">
	<i class="fa fa-plus-square"></i>&nbsp; Add: QEMU Plan
	</a>
	<a class="btn btn-default" href="'. pvewhmcs_BASEURL .'&amp;tab=vmplans&amp;action=add_lxc_plan">
	<i class="fa fa-plus-square"></i>&nbsp; Add: LXC Plan
	</a>
	</div>
	';
	if ($_GET['action']=='add_kvm_plan') {
		kvm_plan_add() ;
	}

	if ($_GET['action']=='editplan') {
		if ($_GET['vmtype']=='kvm')
			kvm_plan_edit($_GET['id']) ;
		else
			lxc_plan_edit($_GET['id']) ;
	}

	if($_GET['action']=='removeplan') {
		remove_plan($_GET['id']) ;
	}


	if ($_GET['action']=='add_lxc_plan') {
		lxc_plan_add() ;
	}

	if ($_GET['action']=='planlist') {
		echo '

		<table class="datatable" border="0" cellpadding="3" cellspacing="1" width="100%">
		<tbody>
		<tr>
		<th>
		ID
		</th>
		<th>
		Name
		</th>
		<th>
		Guest
		</th>
		<th>
		OS Type
		</th>
		<th>
		CPUs
		</th>
		<th>
		Cores
		</th>
		<th>
		RAM
		</th>
  		<th>
		Balloon
		</th>
		<th>
		Swap
		</th>
		<th>
		Disk
		</th>
		<th>
		Disk Type
		</th>
  		<th>
		Disk I/O
		</th>
		<th>
		PVE Store
		</th>
		<th>
		Net Mode
		</th>
		<th>
		Bridge
		</th>
		<th>
		NIC
		</th>
		<th>
		VLAN ID
		</th>
		<th>
		Net Rate
		</th>
		<th>
		Net BW
		</th>
  		<th>
		IPv6
		</th>
		<th>
		Actions
		</th>
		</tr>
		';
		foreach (Capsule::table('mod_pvewhmcs_plans')->get() as $vm) {
			echo '<tr>';
			echo '<td>'.$vm->id . PHP_EOL .'</td>';
			echo '<td>'.$vm->title . PHP_EOL .'</td>';
			echo '<td>'.$vm->vmtype . PHP_EOL .'</td>';
			echo '<td>'.$vm->ostype . PHP_EOL .'</td>';
			echo '<td>'.$vm->cpus . PHP_EOL .'</td>';
			echo '<td>'.$vm->cores . PHP_EOL .'</td>';
			echo '<td>'.$vm->memory . PHP_EOL .'</td>';
			echo '<td>'.$vm->balloon . PHP_EOL .'</td>';
			echo '<td>'.$vm->swap . PHP_EOL .'</td>';
			echo '<td>'.$vm->disk . PHP_EOL .'</td>';
			echo '<td>'.$vm->disktype . PHP_EOL .'</td>';
			echo '<td>'.$vm->diskio . PHP_EOL .'</td>';
			echo '<td>'.$vm->storage . PHP_EOL .'</td>';
			echo '<td>'.$vm->netmode . PHP_EOL .'</td>';
			echo '<td>'.$vm->bridge.$vm->vmbr . PHP_EOL .'</td>';
			echo '<td>'.$vm->netmodel . PHP_EOL .'</td>';
			echo '<td>'.$vm->vlanid . PHP_EOL .'</td>';
			echo '<td>'.$vm->netrate . PHP_EOL .'</td>';
			echo '<td>'.$vm->bw . PHP_EOL .'</td>';
			echo '<td>'.$vm->ipv6 . PHP_EOL .'</td>';
			echo '<td>
			<a href="'.pvewhmcs_BASEURL.'&amp;tab=vmplans&amp;action=editplan&amp;id='.$vm->id.'&amp;vmtype='.$vm->vmtype.'"><img height="16" width="16" border="0" alt="Edit" src="images/edit.gif"></a>
			<a href="'.pvewhmcs_BASEURL.'&amp;tab=vmplans&amp;action=removeplan&amp;id='.$vm->id.'" onclick="return confirm(\'Plan will be deleted, continue?\')"><img height="16" width="16" border="0" alt="Edit" src="images/delete.gif"></a>
			</td>' ;
			echo '</tr>' ;
		}
		echo '
		';
		echo '
		</tbody>
		</table>
		';
	}
	echo '
	</div>
	';
	
	// Determine current IP pool type from GET params for routing
	$current_pool_type = '';
	if (isset($_GET['type']) && ($_GET['type'] == 'ipv4' || $_GET['type'] == 'ipv6')) {
		$current_pool_type = $_GET['type'];
	} elseif (isset($_GET['tab']) && ($_GET['tab'] == 'ippools4' || $_GET['tab'] == 'ippools6')) {
		$current_pool_type = ($_GET['tab'] == 'ippools4' ? 'ipv4' : 'ipv6');
	}


	echo '
	<div id="ippools" class="tab-pane '.(preg_match("/^ippools/", $_GET['tab']) ? "active" : "").'" >
		<div class="btn-group" role="group">
			<a class="btn btn-default" href="'. pvewhmcs_BASEURL .'&amp;tab=ippools&amp;action=list_ip_pools&amp;type=ipv4">
				<i class="fa fa-list"></i>&nbsp; Manage IPv4 Pools
			</a>
			<a class="btn btn-default" href="'. pvewhmcs_BASEURL .'&amp;tab=ippools&amp;action=list_ip_pools&amp;type=ipv6">
				<i class="fa fa-list"></i>&nbsp; Manage IPv6 Pools
			</a>
		</div>
		<hr>
	';
	
	// Routing for actions within IP Pools tab
	if (isset($_GET['action'])) {
		$action = $_GET['action'];
		$pool_type_for_action = isset($_GET['type']) ? $_GET['type'] : null; // Explicit type for action
		
		// For actions like 'newip', 'list_ips', 'removeip', the pool_id is primary identifier.
		// The type of IPs within (IPv4/IPv6) is an attribute of the pool itself.
		// However, 'new_ip_pool' and 'list_ip_pools' are type-specific at creation/listing.

		if ($action == 'list_ip_pools' && $pool_type_for_action) {
			list_ip_pools($pool_type_for_action);
		} elseif ($action == 'new_ip_pool') {
			// If type is not passed directly with new_ip_pool, try to infer from tab or default
			$type_for_new_pool = $pool_type_for_action ?: ($current_pool_type ?: 'ipv4'); // Default to ipv4 if no context
			add_ip_pool($type_for_new_pool);
		} elseif ($action == 'newip') { // Add IP to existing pool
			// add_ip_2_pool will need to fetch pool_type based on $_POST['pool_id']
			add_ip_2_pool(); 
		} elseif ($action == 'removeippool') {
			removeIpPool($_GET['id']); // removeIpPool doesn't strictly need type, just ID
		} elseif ($action == 'list_ips') {
			// list_ips operates on a pool_id. Pool type is an attribute of the pool.
			list_ips(); 
		} elseif ($action == 'removeip') {
			removeip($_GET['id'], $_GET['pool_id']);
		}
	}

	if (isset($_POST['newIPpool'])) { // This is the save_ip_pool action
		save_ip_pool() ; // save_ip_pool will get type from POST
	}
	if (isset($_POST['assignIP2pool'])) { // This is the save action for add_ip_2_pool
        // add_ip_2_pool handles its own POST logic, this check might be redundant if add_ip_2_pool is called above
        // However, to be safe, ensure it's processed if POST is set.
        // The function add_ip_2_pool itself contains the POST handling.
        // No direct call needed here if it's already called via action routing.
    }

	echo'
	</div>
	';
	// NODES / CLUSTER tab in ADMIN GUI
	echo '<div id="nodes" class="tab-pane '.($_GET['tab']=="nodes" ? "active" : "").'" >' ;
	echo ('<strong><h2>PVE: /cluster/resources</h2></strong>');
	echo ('Coming in v1.3.x');
	echo ('<strong><h2>PVE: Cluster Action Viewer</h2></strong>');
	echo ('Coming in v1.3.x');
	echo ('<strong><h2>PVE: Failed Actions (emailed)</h2></strong>');
	echo ('Coming in v1.3.x<br><br>');
	echo ('<strong><a href=\'https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/milestones\' target=\'_blank\'>View the milestones/versions on GitHub</a></strong>');
	echo '</div>';
	// ACTIONS / LOGS tab in ADMIN GUI
	echo '<div id="actions" class="tab-pane '.($_GET['tab']=="actions" ? "active" : "").'" >' ;
	echo ('<strong><h2>Module: Action History</h2></strong>');
	echo ('Coming in v1.3.x');
	echo ('<strong><h2>Module: Failed Actions</h2></strong>');
	echo ('Coming in v1.3.x');
	echo ('<strong><h2>WHMCS: Module Logging</h2></strong>');
	echo ('<u><a href=\'/admin/index.php?rp=/admin/logs/module-log\'>Click here</a></u> (Module Config > Debug Mode = ON)<br><br>');
	echo ('<strong><a href=\'https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/milestones\' target=\'_blank\'>View the milestones/versions on GitHub</a></strong>');
	echo '</div>';
	// SUPPORT / HEALTH tab in ADMIN GUI
	echo '<div id="health" class="tab-pane '.($_GET['tab']=="health" ? "active" : "").'" >' ;
	echo ('<strong><h2>System Environment</h2></strong><b>Proxmox VE for WHMCS</b> v' . pvewhmcs_version() . ' (GitHub reports latest as <b>v' . get_pvewhmcs_latest_version() . '</b>)' . '<br><b>PHP</b> v' . phpversion() . ' running on <b>' . $_SERVER['SERVER_SOFTWARE'] . '</b> Web Server (' . $_SERVER['SERVER_NAME'] . ')<br><br>');
	echo ('<strong><h2>Updates & Codebase</h2></strong><b>Proxmox for WHMCS is open-source and free to use & improve on! ❤️</b><br>Repo: <a href="https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/" target="_blank">https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/</a><br><br>');
	echo ('<strong><h2>Product & Reviewing</h2></strong><b style="color:darkgreen;">Your 5-star review on WHMCS Marketplace will help the module grow!</b><br>*****: <a href="https://marketplace.whmcs.com/product/6935-proxmox-ve-for-whmcs" target="_blank">https://marketplace.whmcs.com/product/6935-proxmox-ve-for-whmcs</a><br><br>');
	echo ('<strong><h2>Issues: Common Causes</h2></strong>1. <b>WHMCS needs to have >100 Services, else it is an illegal Proxmox VMID.</b><br>2. Save your Package (Plan/Pool)! (configproducts.php?action=edit&id=...#tab=3)<br>3. Where possible, we pass-through the exact error to WHMCS Admin. Check it for info!<br><br>');
	echo ('<strong><h2>Module Technical Support</h2></strong>Please raise an <a href="https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/issues/new" target="_blank"><u>Issue</u></a> on GitHub - include logs, steps to reproduce, etc.<br>Help is not guaranteed (FOSS). We will need your assistance. <b>Thank you.</b><br><br>');
	echo '</div>';

	// Config Tab
	$config= Capsule::table('mod_pvewhmcs')->where('id', '=', '1')->get()[0];
	echo '<div id="config" class="tab-pane '.($_GET['tab']=="config" ? "active" : "").'" >' ;
	echo '
	<form method="post">
	<table class="form" border="0" cellpadding="3" cellspacing="1" width="100%">
	<tr>
	<td class="fieldlabel">VNC Secret</td>
	<td class="fieldarea">
	<input type="text" size="35" name="vnc_secret" id="vnc_secret" value="'.$config->vnc_secret.'"> Password of "vnc"@"pve" user. Mandatory for VNC proxying. See the <a href="https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/wiki" target="_blank">Wiki</a> for more info.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Debug Mode</td>
	<td class="fieldarea">
	<label class="checkbox-inline">
	<input type="checkbox" name="debug_mode" value="1" '. ($config->debug_mode=="1" ? "checked" : "").'> Whether or not you want Debug Logging enabled (WHMCS Module Log for Debugging >> /admin/logs/module-log)
	</label>
	</td>
	</tr>
	</table>
	<div class="btn-container">
	<input type="submit" class="btn btn-primary" value="Save Changes" name="save_config" id="save_config">
	<input type="reset" class="btn btn-default" value="Cancel Changes">
	</div>
	</form>
	';
	
	echo '</div>';

	echo '</div>'; // end of tab-content

	if (isset($_POST['save_config'])) {
		save_config() ;
	}
}

// MODULE CONFIG: Commit changes to the database
function save_config() {
	try {
		Capsule::connection()->transaction(
			function ($connectionManager)
			{
				/** @var \Illuminate\Database\Connection $connectionManager */
				$connectionManager->table('mod_pvewhmcs')->update(
					[
						'vnc_secret' => $_POST['vnc_secret'],
						'debug_mode' => $_POST['debug_mode'],
					]
				);
			}
		);
		$_SESSION['pvewhmcs']['infomsg']['title']='Module Config saved.' ;
		$_SESSION['pvewhmcs']['infomsg']['message']='New options have been successfully saved.' ;
		header("Location: ".pvewhmcs_BASEURL."&tab=config");
	} catch (\Exception $e) {
		echo "Uh oh! That didn't work, but I was able to rollback. {$e->getMessage()}";
	}
}

// MODULE FORM: Add new KVM Plan
function kvm_plan_add() {
	echo '
	<form method="post">
	<table class="form" border="0" cellpadding="3" cellspacing="1" width="100%">
	<tr>
	<td class="fieldlabel">Plan Title</td>
	<td class="fieldarea">
	<input type="text" size="35" name="title" id="title" required>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">OS - Type</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="ostype">
	<option value="l26">Linux 6.x - 2.6 Kernel</option>
	<option value="l24">Linux 2.4 Kernel</option>
	<option value="solaris">Solaris Kernel</option>
	<option value="win11">Windows 11 / 2022</option>
	<option value="win10">Windows 10 / 2016 / 2019</option>
	<option value="win8">Windows 8.x / 2012 / 2012r2</option>
	<option value="win7">Windows 7 / 2008r2</option>
	<option value="wvista">Windows Vista / 2008</option>
	<option value="wxp">Windows XP / 2003</option>
	<option value="w2k">Windows 2000</option>
	<option value="other">Other</option>
	</select>
	Kernel type (Linux, Windows, etc).
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Emulation</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="cpuemu">
	<option value="host">(Host) Host</option>
	<option value="kvm32">(QEMU) kvm32</option>
	<option value="kvm64">(QEMU) kvm64</option>
	<option value="max">(QEMU) Max</option>
	<option value="qemu32">(QEMU) qemu32</option>
	<option value="qemu64">(QEMU) qemu64</option>
	<option value="x86-64-v2">(x86-64 psABI) v2 (Nehalem/Opteron_G3 on)</option>
	<option value="x86-64-v2-AES" selected="">(x86-64 psABI) v2-AES (Westmere/Opteron_G4 on)</option>
	<option value="x86-64-v3">(x86-64 psABI) v3 (Broadwell/EPYC on)</option>
	<option value="x86-64-v4">(x86-64 psABI) v4 (Skylake/EPYCv4 on)</option>
	<option value="486">(Intel) 486</option>
	<option value="Broadwell">(Intel) Broadwell</option>
	<option value="Broadwell-IBRS">(Intel) Broadwell-IBRS</option>
	<option value="Broadwell-noTSX">(Intel) Broadwell-noTSX</option>
	<option value="Broadwell-noTSX-IBRS">(Intel) Broadwell-noTSX-IBRS</option>
	<option value="Cascadelake-Server">(Intel) Cascadelake-Server</option>
	<option value="Cascadelake-Server-noTSX">(Intel) Cascadelake-Server-noTSX</option>
	<option value="Cascadelake-Server-v2">(Intel) Cascadelake-Server-v2</option>
	<option value="Cascadelake-Server-v4">(Intel) Cascadelake-Server-v4</option>
	<option value="Cascadelake-Server-v5">(Intel) Cascadelake-Server-v5</option>
	<option value="Conroe">(Intel) Conroe</option>
	<option value="Cooperlake">(Intel) Cooperlake</option>
	<option value="Cooperlake-v2">(Intel) Cooperlake-v2</option>
	<option value="Haswell">(Intel) Haswell</option>
	<option value="Haswell-IBRS">(Intel) Haswell-IBRS</option>
	<option value="Haswell-noTSX">(Intel) Haswell-noTSX</option>
	<option value="Haswell-noTSX-IBRS">(Intel) Haswell-noTSX-IBRS</option>
	<option value="Icelake-Client">(Intel) Icelake-Client</option>
	<option value="Icelake-Client-noTSX">(Intel) Icelake-Client-noTSX</option>
	<option value="Icelake-Server">(Intel) Icelake-Server</option>
	<option value="Icelake-Server-noTSX">(Intel) Icelake-Server-noTSX</option>
	<option value="Icelake-Server-v3">(Intel) Icelake-Server-v3</option>
	<option value="Icelake-Server-v4">(Intel) Icelake-Server-v4</option>
	<option value="Icelake-Server-v5">(Intel) Icelake-Server-v5</option>
	<option value="Icelake-Server-v6">(Intel) Icelake-Server-v6</option>
	<option value="IvyBridge">(Intel) IvyBridge</option>
	<option value="IvyBridge-IBRS">(Intel) IvyBridge-IBRS</option>
	<option value="KnightsMill">(Intel) KnightsMill</option>
	<option value="Nehalem">(Intel) Nehalem</option>
	<option value="Nehalem-IBRS">(Intel) Nehalem-IBRS</option>
	<option value="Penryn">(Intel) Penryn</option>
	<option value="SandyBridge">(Intel) SandyBridge</option>
	<option value="SandyBridge-IBRS">(Intel) SandyBridge-IBRS</option>
	<option value="SapphireRapids">(Intel) SapphireRapids</option>
	<option value="Skylake-Client">(Intel) Skylake-Client</option>
	<option value="Skylake-Client-IBRS">(Intel) Skylake-Client-IBRS</option>
	<option value="Skylake-Client-noTSX-IBRS">(Intel) Skylake-Client-noTSX-IBRS</option>
	<option value="Skylake-Client-v4">(Intel) Skylake-Client-v4</option>
	<option value="Skylake-Server">(Intel) Skylake-Server</option>
	<option value="Skylake-Server-IBRS">(Intel) Skylake-Server-IBRS</option>
	<option value="Skylake-Server-noTSX-IBRS">(Intel) Skylake-Server-noTSX-IBRS</option>
	<option value="Skylake-Server-v4">(Intel) Skylake-Server-v4</option>
	<option value="Skylake-Server-v5">(Intel) Skylake-Server-v5</option>
	<option value="Westmere">(Intel) Westmere</option>
	<option value="Westmere-IBRS">(Intel) Westmere-IBRS</option>
	<option value="pentium">(Intel) Pentium I</option>
	<option value="pentium2">(Intel) Pentium II</option>
	<option value="pentium3">(Intel) Pentium III</option>
	<option value="coreduo">(Intel) Core Duo</option>
	<option value="core2duo">(Intel) Core 2 Duo</option>
	<option value="athlon">(AMD) Athlon</option>
	<option value="phenom">(AMD) Phenom</option>
	<option value="EPYC">(AMD) EPYC</option>
	<option value="EPYC-IBPB">(AMD) EPYC-IBPB</option>
	<option value="EPYC-Milan">(AMD) EPYC-Milan</option>
	<option value="EPYC-Rome">(AMD) EPYC-Rome</option>
	<option value="EPYC-Rome-v2">(AMD) EPYC-Rome-v2</option>
	<option value="EPYC-v3">(AMD) EPYC-v3</option>
	<option value="Opteron_G1">(AMD) Opteron_G1</option>
	<option value="Opteron_G2">(AMD) Opteron_G2</option>
	<option value="Opteron_G3">(AMD) Opteron_G3</option>
	<option value="Opteron_G4">(AMD) Opteron_G4</option>
	<option value="Opteron_G5">(AMD) Opteron_G5</option>
	</select>
	CPU emulation type. Default is x86-64 psABI v2-AES
	</td>
	</tr>

	<tr>
	<td class="fieldlabel">CPU - Sockets</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpus" id="cpus" value="1" required>
	The number of CPU Sockets. 1 - 4.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Cores</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cores" id="cores" value="1" required>
	The number of CPU Cores per socket. 1 - 32.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Limit</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpulimit" id="cpulimit" value="0" required>
	Limit of CPU Usage. Note if the Server has 2 CPUs, it has total of "2" CPU time. Value "0" indicates no CPU limit.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Weighting</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpuunits" id="cpuunits" value="1024" required>
	Number is relative to weights of all the other running VMs. 8 - 500000, recommend 1024. NOTE: Disable fair-scheduler by setting this to 0.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">RAM - Memory</td>
	<td class="fieldarea">
	<input type="text" size="8" name="memory" id="memory" value="2048" required>
	RAM space in Megabyte e.g 1024 = 1GB (default is 2GB)
	</td>
	</tr>
 	<tr>
	<td class="fieldlabel">RAM - Balloon</td>
	<td class="fieldarea">
	<input type="text" size="8" name="balloon" id="balloon" value="0" required>
	Balloon space in Megabyte e.g 1024 = 1GB (0 = disabled)
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Capacity</td>
	<td class="fieldarea">
	<input type="text" size="8" name="disk" id="disk" value="10240" required>
	HDD/SSD storage space in Gigabyte e.g 1024 = 1TB (default is 10GB)
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Format</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="diskformat">
	<option value="raw">Disk Image (raw)</option>
	<option selected="" value="qcow2">QEMU Image (qcow2)</option>
	<option value="vmdk">VMware Image (vmdk)</option>
	</select>
	Recommend "QEMU/qcow2" (so it can make Snapshots)
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Cache</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="diskcache">
	<option selected="" value="">No Cache (Default)</option>
	<option value="directsync">Direct Sync</option>
	<option value="writethrough">Write Through</option>
	<option value="writeback">Write Back</option>
	<option value="unsafe">Write Back (Unsafe)</option>
	<option value="none">No Cache</option>
	</select>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Type</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="disktype">
	<option selected="" value="virtio">Virtio</option>
	<option value="scsi">SCSI</option>
	<option value="sata">SATA</option>
	<option value="ide">IDE</option>
	</select>
	Virtio is the fastest option, then SCSI, then SATA, etc.
	</td>
	</tr>
 	<tr>
	<td class="fieldlabel">Disk - I/O Cap</td>
	<td class="fieldarea">
	<input type="text" size="8" name="diskio" id="diskio" value="0" required>
	Limit of Disk I/O in KiB/s. 0 for unrestricted storage access.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">PVE Store - Name</td>
	<td class="fieldarea">
	<input type="text" size="8" name="storage" id="storage" value="local" required>
	Name of VM/CT Storage on Proxmox VE hypervisor. local/local-lvm/etc.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">NIC - Type</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="netmodel">
	<option selected="" value="e1000">Intel E1000 (Reliable)</option>
	<option value="virtio">VirtIO (Paravirtualized)</option>
	<option value="rtl8139">Realtek RTL8139</option>
	<option value="vmxnet3">VMware vmxnet3</option>
	</select>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Rate</td>
	<td class="fieldarea">
	<input type="text" size="8" name="netrate" id="netrate" value="0">
	Network Rate Limit in Megabit/Second. Zero for unlimited.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - BW Limit</td>
	<td class="fieldarea">
	<input type="text" size="8" name="bw" id="bw">
	Monthly Bandwidth Limit in Gigabytes. Blank for unlimited.
	</td>
	</tr>
 	<tr>
	<td class="fieldlabel">Network - IPv6 Conf.</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="ipv6">
	<option value="0">Off</option>
	<option value="auto">SLAAC</option>
	<option value="dhcp">DHCPv6</option>
	<option value="prefix">Prefix</option>
	</select>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Mode</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="netmode">
	<option value="bridge">Bridge</option>
	<option value="nat">NAT</option>
	<option value="none">No Network</option>
	</select>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Interface</td>
	<td class="fieldarea">
	<input type="text" size="8" name="bridge" id="bridge" value="vmbr">
	Network / Bridge / NIC name. PVE default bridge prefix is "vmbr".
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Bridge/NIC ID</td>
	<td class="fieldarea">
	<input type="text" size="8" name="vmbr" id="vmbr" value="0">
	Interface ID. PVE Bridge default is 0, for "vmbr0". PVE SDN, leave blank.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - VLAN ID</td>
	<td class="fieldarea">
	<input type="text" size="8" name="vlanid" id="vlanid">
	VLAN ID for Plan Services. Default forgoes tagging (VLAN ID), blank for untagged.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">
	Hardware Virt?
	</td>
	<td class="fieldarea">
	<label class="checkbox-inline">
	<input type="checkbox" name="kvm" value="1" checked> Enable KVM hardware virtualisation. Requires support/enablement in BIOS. (Recommended)
	</label>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">
	On-boot VM?
	</td>
	<td class="fieldarea">
	<label class="checkbox-inline">
	<input type="checkbox" name="onboot" value="1" checked> Specifies whether a VM will be started during hypervisor boot-up. (Recommended)
	</label>
	</td>
	</tr>
	</table>

	<div class="btn-container">
	<input type="submit" class="btn btn-primary" value="Save Changes" name="addnewkvmplan" id="addnewkvmplan">
	<input type="reset" class="btn btn-default" value="Cancel Changes">
	</div>
	</form>
	';
}

// MODULE FORM: Edit a KVM Plan
function kvm_plan_edit($id) {
	$plan= Capsule::table('mod_pvewhmcs_plans')->where('id', '=', $id)->get()[0];
	if (empty($plan)) {
		echo 'Plan Not found' ;
		return false ;
	}
	echo '<pre>' ;
		//print_r($plan) ;
	echo '</pre>' ;
	echo '
	<form method="post">
	<table class="form" border="0" cellpadding="3" cellspacing="1" width="100%">
	<tr>
	<td class="fieldlabel">Plan Title</td>
	<td class="fieldarea">
	<input type="text" size="35" name="title" id="title" required value="'.$plan->title.'">
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">OS - Type</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="ostype">
	<option value="l26" ' . ($plan->ostype == "l26" ? "selected" : "") . '>Linux 6.x - 2.6 Kernel</option>
	<option value="l24" ' . ($plan->ostype == "l24" ? "selected" : "") . '>Linux 2.4 Kernel</option>
	<option value="solaris" ' . ($plan->ostype == "solaris" ? "selected" : "") . '>Solaris Kernel</option>
	<option value="win11" ' . ($plan->ostype == "win11" ? "selected" : "") . '>Windows 11 / 2022</option>
	<option value="win10" ' . ($plan->ostype == "win10" ? "selected" : "") . '>Windows 10 / 2016 / 2019</option>
	<option value="win8" ' . ($plan->ostype == "win8" ? "selected" : "") . '>Windows 8.x / 2012 / 2012r2</option>
	<option value="win7" ' . ($plan->ostype == "win7" ? "selected" : "") . '>Windows 7 / 2008r2</option>
	<option value="wvista" ' . ($plan->ostype == "wvista" ? "selected" : "") . '>Windows Vista / 2008</option>
	<option value="wxp" ' . ($plan->ostype == "wxp" ? "selected" : "") . '>Windows XP / 2003</option>
	<option value="w2k" ' . ($plan->ostype == "w2k" ? "selected" : "") . '>Windows 2000</option>
	<option value="other" ' . ($plan->ostype == "other" ? "selected" : "") . '>Other</option>
	</select>
	Kernel type (Linux, Windows, etc).
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Emulation</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="cpuemu">
	<option value="host" ' . ($plan->cpuemu == "host" ? "selected" : "") . '>Host</option>
	<option value="kvm32" ' . ($plan->cpuemu == "kvm32" ? "selected" : "") . '>(QEMU) kvm32</option>
	<option value="kvm64" ' . ($plan->cpuemu == "kvm64" ? "selected" : "") . '>(QEMU) kvm64</option>
	<option value="max" ' . ($plan->cpuemu == "max" ? "selected" : "") . '>(QEMU) Max</option>
	<option value="qemu32" ' . ($plan->cpuemu == "qemu32" ? "selected" : "") . '>(QEMU) qemu32</option>
	<option value="qemu64" ' . ($plan->cpuemu == "qemu64" ? "selected" : "") . '>(QEMU) qemu64</option>
	<option value="x86-64-v2" ' . ($plan->cpuemu == "x86-64-v2" ? "selected" : "") . '>(x86-64 psABI) v2 (Nehalem/Opteron_G3 on)</option>
	<option value="x86-64-v2-AES" ' . ($plan->cpuemu == "x86-64-v2-AES" ? "selected" : "") . '>(x86-64 psABI) v2-AES (Westmere/Opteron_G4 on)</option>
	<option value="x86-64-v3" ' . ($plan->cpuemu == "x86-64-v3" ? "selected" : "") . '>(x86-64 psABI) v3 (Broadwell/EPYC on)</option>
	<option value="x86-64-v4" ' . ($plan->cpuemu == "x86-64-v4" ? "selected" : "") . '>(x86-64 psABI) v4 (Skylake/EPYCv4 on)</option>
	<option value="486" ' . ($plan->cpuemu == "486" ? "selected" : "") . '>(Intel) 486</option>
	<option value="Broadwell" ' . ($plan->cpuemu == "Broadwell" ? "selected" : "") . '>(Intel) Broadwell</option>
	<option value="Broadwell-IBRS" ' . ($plan->cpuemu == "Broadwell-IBRS" ? "selected" : "") . '>(Intel) Broadwell-IBRS</option>
	<option value="Broadwell-noTSX" ' . ($plan->cpuemu == "Broadwell-noTSX" ? "selected" : "") . '>(Intel) Broadwell-noTSX</option>
	<option value="Broadwell-noTSX-IBRS" ' . ($plan->cpuemu == "Broadwell-noTSX-IBRS" ? "selected" : "") . '>(Intel) Broadwell-noTSX-IBRS</option>
	<option value="Cascadelake-Server" ' . ($plan->cpuemu == "Cascadelake-Server" ? "selected" : "") . '>(Intel) Cascadelake-Server</option>
	<option value="Cascadelake-Server-noTSX" ' . ($plan->cpuemu == "Cascadelake-Server-noTSX" ? "selected" : "") . '>(Intel) Cascadelake-Server-noTSX</option>
	<option value="Cascadelake-Server-v2" ' . ($plan->cpuemu == "Cascadelake-Server-v2" ? "selected" : "") . '>(Intel) Cascadelake-Server V2</option>
	<option value="Cascadelake-Server-v4" ' . ($plan->cpuemu == "Cascadelake-Server-v4" ? "selected" : "") . '>(Intel) Cascadelake-Server V4</option>
	<option value="Cascadelake-Server-v5" ' . ($plan->cpuemu == "Cascadelake-Server-v5" ? "selected" : "") . '>(Intel) Cascadelake-Server V5</option>
	<option value="Conroe" ' . ($plan->cpuemu == "Conroe" ? "selected" : "") . '>(Intel) Conroe</option>
	<option value="Cooperlake" ' . ($plan->cpuemu == "Cooperlake" ? "selected" : "") . '>(Intel) Cooperlake</option>
	<option value="Cooperlake-v2" ' . ($plan->cpuemu == "Cooperlake-v2" ? "selected" : "") . '>(Intel) Cooperlake V2</option>
	<option value="Haswell" ' . ($plan->cpuemu == "Haswell" ? "selected" : "") . '>(Intel) Haswell</option>
	<option value="Haswell-IBRS" ' . ($plan->cpuemu == "Haswell-IBRS" ? "selected" : "") . '>(Intel) Haswell-IBRS</option>
	<option value="Haswell-noTSX" ' . ($plan->cpuemu == "Haswell-noTSX" ? "selected" : "") . '>(Intel) Haswell-noTSX</option>
	<option value="Haswell-noTSX-IBRS" ' . ($plan->cpuemu == "Haswell-noTSX-IBRS" ? "selected" : "") . '>(Intel) Haswell-noTSX-IBRS</option>
	<option value="Icelake-Client" ' . ($plan->cpuemu == "Icelake-Client" ? "selected" : "") . '>(Intel) Icelake-Client</option>
	<option value="Icelake-Client-noTSX" ' . ($plan->cpuemu == "Icelake-Client-noTSX" ? "selected" : "") . '>(Intel) Icelake-Client-noTSX</option>
	<option value="Icelake-Server" ' . ($plan->cpuemu == "Icelake-Server" ? "selected" : "") . '>(Intel) Icelake-Server</option>
	<option value="Icelake-Server-noTSX" ' . ($plan->cpuemu == "Icelake-Server-noTSX" ? "selected" : "") . '>(Intel) Icelake-Server-noTSX</option>
	<option value="Icelake-Server-v3" ' . ($plan->cpuemu == "Icelake-Server-v3" ? "selected" : "") . '>(Intel) Icelake-Server V3</option>
	<option value="Icelake-Server-v4" ' . ($plan->cpuemu == "Icelake-Server-v4" ? "selected" : "") . '>(Intel) Icelake-Server V4</option>
	<option value="Icelake-Server-v5" ' . ($plan->cpuemu == "Icelake-Server-v5" ? "selected" : "") . '>(Intel) Icelake-Server V5</option>
	<option value="Icelake-Server-v6" ' . ($plan->cpuemu == "Icelake-Server-v6" ? "selected" : "") . '>(Intel) Icelake-Server V6</option>
	<option value="IvyBridge" ' . ($plan->cpuemu == "IvyBridge" ? "selected" : "") . '>(Intel) IvyBridge</option>
	<option value="IvyBridge-IBRS" ' . ($plan->cpuemu == "IvyBridge-IBRS" ? "selected" : "") . '>(Intel) IvyBridge-IBRS</option>
	<option value="KnightsMill" ' . ($plan->cpuemu == "KnightsMill" ? "selected" : "") . '>(Intel) KnightsMill</option>
	<option value="Nehalem" ' . ($plan->cpuemu == "Nehalem" ? "selected" : "") . '>(Intel) Nehalem</option>
	<option value="Nehalem-IBRS" ' . ($plan->cpuemu == "Nehalem-IBRS" ? "selected" : "") . '>(Intel) Nehalem-IBRS</option>
	<option value="Penryn" ' . ($plan->cpuemu == "Penryn" ? "selected" : "") . '>(Intel) Penryn</option>
	<option value="SandyBridge" ' . ($plan->cpuemu == "SandyBridge" ? "selected" : "") . '>(Intel) SandyBridge</option>
	<option value="SandyBridge-IBRS" ' . ($plan->cpuemu == "SandyBridge-IBRS" ? "selected" : "") . '>(Intel) SandyBridge-IBRS</option>
	<option value="SapphireRapids" ' . ($plan->cpuemu == "SapphireRapids" ? "selected" : "") . '>(Intel) Sapphire Rapids</option>
	<option value="Skylake-Client" ' . ($plan->cpuemu == "Skylake-Client" ? "selected" : "") . '>(Intel) Skylake-Client</option>
	<option value="Skylake-Client-IBRS" ' . ($plan->cpuemu == "Skylake-Client-IBRS" ? "selected" : "") . '>(Intel) Skylake-Client-IBRS</option>
	<option value="Skylake-Client-noTSX-IBRS" ' . ($plan->cpuemu == "Skylake-Client-noTSX-IBRS" ? "selected" : "") . '>(Intel) Skylake-Client-noTSX-IBRS</option>
	<option value="Skylake-Client-v4" ' . ($plan->cpuemu == "Skylake-Client-v4" ? "selected" : "") . '>(Intel) Skylake-Client V4</option>
	<option value="Skylake-Server" ' . ($plan->cpuemu == "Skylake-Server" ? "selected" : "") . '>(Intel) Skylake-Server</option>
	<option value="Skylake-Server-IBRS" ' . ($plan->cpuemu == "Skylake-Server-IBRS" ? "selected" : "") . '>(Intel) Skylake-Server-IBRS</option>
	<option value="Skylake-Server-noTSX-IBRS" ' . ($plan->cpuemu == "Skylake-Server-noTSX-IBRS" ? "selected" : "") . '>(Intel) Skylake-Server-noTSX-IBRS</option>
	<option value="Skylake-Server-v4" ' . ($plan->cpuemu == "Skylake-Server-v4" ? "selected" : "") . '>(Intel) Skylake-Server V4</option>
	<option value="Skylake-Server-v5" ' . ($plan->cpuemu == "Skylake-Server-v5" ? "selected" : "") . '>(Intel) Skylake-Server V5</option>
	<option value="Westmere" ' . ($plan->cpuemu == "Westmere" ? "selected" : "") . '>(Intel) Westmere</option>
	<option value="Westmere-IBRS" ' . ($plan->cpuemu == "Westmere-IBRS" ? "selected" : "") . '>(Intel) Westmere-IBRS</option>
	<option value="pentium" ' . ($plan->cpuemu == "pentium" ? "selected" : "") . '>(Intel) Pentium I</option>
	<option value="pentium2" ' . ($plan->cpuemu == "pentium2" ? "selected" : "") . '>(Intel) Pentium II</option>
	<option value="pentium3" ' . ($plan->cpuemu == "pentium3" ? "selected" : "") . '>(Intel) Pentium III</option>
	<option value="coreduo" ' . ($plan->cpuemu == "coreduo" ? "selected" : "") . '>(Intel) Core Duo</option>
	<option value="core2duo" ' . ($plan->cpuemu == "core2duo" ? "selected" : "") . '>(Intel) Core 2 Duo</option>
	<option value="athlon" ' . ($plan->cpuemu == "athlon" ? "selected" : "") . '>(AMD) Athlon</option>
	<option value="phenom" ' . ($plan->cpuemu == "phenom" ? "selected" : "") . '>(AMD) Phenom</option>
	<option value="EPYC" ' . ($plan->cpuemu == "EPYC" ? "selected" : "") . '>(AMD) EPYC</option>
	<option value="EPYC-IBPB" ' . ($plan->cpuemu == "EPYC-IBPB" ? "selected" : "") . '>(AMD) EPYC-IBPB</option>
	<option value="EPYC-Milan" ' . ($plan->cpuemu == "EPYC-Milan" ? "selected" : "") . '>(AMD) EPYC-Milan</option>
	<option value="EPYC-Rome" ' . ($plan->cpuemu == "EPYC-Rome" ? "selected" : "") . '>(AMD) EPYC-Rome</option>
	<option value="EPYC-Rome-v2" ' . ($plan->cpuemu == "EPYC-Rome-v2" ? "selected" : "") . '>(AMD) EPYC-Rome-v2</option>
	<option value="EPYC-v3" ' . ($plan->cpuemu == "EPYC-v3" ? "selected" : "") . '>(AMD) EPYC-v3</option>
	<option value="Opteron_G1" ' . ($plan->cpuemu == "Opteron_G1" ? "selected" : "") . '>(AMD) Opteron_G1</option>
	<option value="Opteron_G2" ' . ($plan->cpuemu == "Opteron_G2" ? "selected" : "") . '>(AMD) Opteron_G2</option>
	<option value="Opteron_G3" ' . ($plan->cpuemu == "Opteron_G3" ? "selected" : "") . '>(AMD) Opteron_G3</option>
	<option value="Opteron_G4" ' . ($plan->cpuemu == "Opteron_G4" ? "selected" : "") . '>(AMD) Opteron_G4</option>
	<option value="Opteron_G5" ' . ($plan->cpuemu == "Opteron_G5" ? "selected" : "") . '>(AMD) Opteron_G5</option>
	</select>
	CPU emulation type. Default is x86-64 psABI v2-AES
	</td>
	</tr>

	<tr>
	<td class="fieldlabel">CPU - Sockets</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpus" id="cpus" value="'.$plan->cpus.'" required>
	The number of CPU sockets. 1 - 4.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Cores</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cores" id="cores" value="'.$plan->cores.'" required>
	The number of CPU cores per socket. 1 - 32.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Limit</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpulimit" id="cpulimit" value="'.$plan->cpulimit.'" required>
	Limit of CPU usage. Note if the computer has 2 CPUs, it has total of "2" CPU time. Value "0" indicates no CPU limit.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Weighting</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpuunits" id="cpuunits" value="'.$plan->cpuunits.'" required>
	Number is relative to weights of all the other running VMs. 8 - 500000 recommended 1024. NOTE: You can disable fair-scheduler by setting this to 0.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">RAM - Memory</td>
	<td class="fieldarea">
	<input type="text" size="8" name="memory" id="memory" required value="'.$plan->memory.'">
	RAM space in Megabytes e.g 1024 = 1GB
	</td>
	</tr>
  	<tr>
	<td class="fieldlabel">RAM - Balloon</td>
	<td class="fieldarea">
	<input type="text" size="8" name="balloon" id="balloon" required value="'.$plan->balloon.'">
	Balloon space in Megabyte e.g 1024 = 1GB (0 = disabled)
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Capacity</td>
	<td class="fieldarea">
	<input type="text" size="8" name="disk" id="disk" required value="'.$plan->disk.'">
	HDD/SSD storage space in Gigabytes e.g 1024 = 1TB
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Format</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="diskformat">
	<option value="raw" '. ($plan->diskformat=="raw" ? "selected" : "").'>Disk Image (raw)</option>
	<option value="qcow2" '. ($plan->diskformat=="qcow2" ? "selected" : "").'>QEMU image (qcow2)</option>
	<option value="vmdk" '. ($plan->diskformat=="vmdk" ? "selected" : "").'>VMware image (vmdk)</option>
	</select>
	Recommend "QEMU/qcow2 format" (to make Snapshots)
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Cache</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="diskcache">
	<option value="" '. ($plan->diskcache=="" ? "selected" : "").'>No Cache (Default)</option>
	<option value="directsync" '. ($plan->diskcache=="directsync" ? "selected" : "").'>Direct Sync</option>
	<option value="writethrough" '. ($plan->diskcache=="writethrough" ? "selected" : "").'>Write Through</option>
	<option value="writeback" '. ($plan->diskcache=="writeback" ? "selected" : "").'>Write Back</option>
	<option value="unsafe" '. ($plan->diskcache=="unsafe" ? "selected" : "").'>Write Back (Unsafe)</option>
	<option value="none" '. ($plan->diskcache=="none" ? "selected" : "").'>No Cache</option>
	</select>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Type</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="disktype">
	<option value="virtio" '. ($plan->disktype=="virtio" ? "selected" : "").'>Virtio</option>
	<option value="scsi" '. ($plan->disktype=="scsi" ? "selected" : "").'>SCSI</option>
	<option value="sata" '. ($plan->disktype=="sata" ? "selected" : "").'>SATA</option>
	<option value="ide" '. ($plan->disktype=="ide" ? "selected" : "").'>IDE</option>
	</select>
	Virtio is the fastest option, then SCSI, then SATA, etc.
	</td>
	</tr>
 	<tr>
	<td class="fieldlabel">Disk - I/O Cap</td>
	<td class="fieldarea">
	<input type="text" size="8" name="diskio" id="diskio" required value="'.$plan->diskio.'">
	Limit of Disk I/O in KiB/s. 0 for unrestricted storage access.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">PVE Store - Name</td>
	<td class="fieldarea">
	<input type="text" size="8" name="storage" id="storage" required value="'.$plan->storage.'">
	Name of VM/CT Storage on Proxmox VE hypervisor. local/local-lvm/etc.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">NIC - Type</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="netmodel">
	<option value="e1000" '. ($plan->netmodel=="e1000" ? "selected" : "").'>Intel E1000 (Reliable)</option>
	<option value="virtio" '. ($plan->netmodel=="virtio" ? "selected" : "").'>VirtIO (Paravirt)</option>
	<option value="rtl8139" '. ($plan->netmodel=="rtl8139" ? "selected" : "").'>Realtek RTL8139</option>
	<option value="vmxnet3" '. ($plan->netmodel=="vmxnet3" ? "selected" : "").'>VMware vmxnet3</option>
	</select>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Rate</td>
	<td class="fieldarea">
	<input type="text" size="8" name="netrate" id="netrate" value="'.$plan->netrate.'">
	Network Rate Limit in Megabit. Zero for unlimited.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - BW Limit</td>
	<td class="fieldarea">
	<input type="text" size="8" name="bw" id="bw" value="'.$plan->bw.'">
	Monthly Bandwidth Limit in Gigabyte. Blank for unlimited.
	</td>
	</tr>
  	<tr>
	<td class="fieldlabel">Network - IPv6 Conf.</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="ipv6">
	<option value="0" '. ($plan->ipv6=="0" ? "selected" : "").'>Off</option>
	<option value="auto" '. ($plan->ipv6=="auto" ? "selected" : "").'>SLAAC</option>
	<option value="dhcp" '. ($plan->ipv6=="dhcp" ? "selected" : "").'>DHCPv6</option>
	<option value="prefix" '. ($plan->ipv6=="prefix" ? "selected" : "").'>Prefix</option>
	</select>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Mode</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="netmode">
	<option value="bridge" '. ($plan->netmode=="bridge" ? "selected" : "").'>Bridge</option>
	<option value="nat" '. ($plan->netmode=="nat" ? "selected" : "").'>NAT</option>
	<option value="none" '. ($plan->netmode=="none" ? "selected" : "").'>No network</option>
	</select>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Interface</td>
	<td class="fieldarea">
	<input type="text" size="8" name="bridge" id="bridge" value="'.$plan->bridge.'">
	Network / Bridge / NIC name. PVE default bridge prefix is "vmbr".
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Bridge/NIC ID</td>
	<td class="fieldarea">
	<input type="text" size="8" name="vmbr" id="vmbr" value="'.$plan->vmbr.'">
	Interface ID. PVE Bridge default is 0, for "vmbr0". PVE SDN, leave blank.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - VLAN ID</td>
	<td class="fieldarea">
	<input type="text" size="8" name="vlanid" id="vlanid">
	VLAN ID for Plan Services. Default forgoes tagging (VLAN ID), blank for untagged.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">
	Hardware Virt?
	</td>
	<td class="fieldarea">
	<label class="checkbox-inline">
	<input type="checkbox" name="kvm" value="1" '. ($plan->kvm=="1" ? "checked" : "").'> Enable KVM hardware virtualisation. Requires support/enablement in BIOS. (Recommended)
	</label>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">
	On-boot VM?
	</td>
	<td class="fieldarea">
	<label class="checkbox-inline">
	<input type="checkbox" name="onboot" value="1" '. ($plan->onboot=="1" ? "checked" : "").'> Specifies whether a VM will be started during hypervisor boot-up. (Recommended)
	</label>
	</td>
	</tr>
	</table>

	<div class="btn-container">
	<input type="submit" class="btn btn-primary" value="Save Changes" name="updatekvmplan" id="saveeditedkvmplan">
	<input type="reset" class="btn btn-default" value="Cancel Changes">
	</div>
	</form>
	';
}

// MODULE FORM: Add an LXC Plan
function lxc_plan_add() {
	echo '
	<form method="post">
	<table class="form" border="0" cellpadding="3" cellspacing="1" width="100%">
	<tr>
	<td class="fieldlabel">Plan Title</td>
	<td class="fieldarea">
	<input type="text" size="35" name="title" id="title" required>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Limit</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpulimit" id="cpulimit" value="1" required>
	Limit of CPU usage. Default is 1. Note: if the computer has 2 CPUs, it has total of "2" CPU time. Value "0" indicates no CPU limit.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Weighting</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpuunits" id="cpuunits" value="1024" required>
	Number is relative to weights of all the other running VMs. 8 - 500000, recommend 1024.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">RAM - Memory</td>
	<td class="fieldarea">
	<input type="text" size="8" name="memory" id="memory" required>
	RAM space in Megabytes e.g 1024 = 1GB
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Swap - Space</td>
	<td class="fieldarea">
	<input type="text" size="8" name="swap" id="swap">
	Swap space in Megabytes e.g 1024 = 1GB
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Capacity</td>
	<td class="fieldarea">
	<input type="text" size="8" name="disk" id="disk" required>
	HDD/SSD storage space in Gigabytes e.g 1024 = 1TB
	</td>
	</tr>
 	<tr>
	<td class="fieldlabel">Disk - I/O Cap</td>
	<td class="fieldarea">
	<input type="text" size="8" name="diskio" id="diskio" value="0" required>
	Limit of Disk I/O in KiB/s. 0 for unrestricted storage access.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">PVE Store - Name</td>
	<td class="fieldarea">
	<input type="text" size="8" name="storage" id="storage" value="local" required>
	Name of VM/CT Storage on Proxmox VE hypervisor. local/local-lvm/etc.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Interface</td>
	<td class="fieldarea">
	<input type="text" size="8" name="bridge" id="bridge" value="vmbr">
	Network / Bridge / NIC name. PVE default bridge prefix is "vmbr".
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Bridge/NIC ID</td>
	<td class="fieldarea">
	<input type="text" size="8" name="vmbr" id="vmbr" value="0">
	Interface ID. PVE Bridge default is 0, for "vmbr0". PVE SDN, leave blank.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - VLAN ID</td>
	<td class="fieldarea">
	<input type="text" size="8" name="vlanid" id="vlanid">
	VLAN ID for Plan Services. Default forgoes tagging (VLAN ID), blank for untagged.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Rate</td>
	<td class="fieldarea">
	<input type="text" size="8" name="netrate" id="netrate" value="0">
	Network Rate Limit in Megabit/Second. Zero for unlimited.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Data - Monthly</td>
	<td class="fieldarea">
	<input type="text" size="8" name="bw" id="bw">
	Monthly Bandwidth Limit in Gigabytes. Blank for unlimited.
	</td>
	</tr>
  	<tr>
	<td class="fieldlabel">Network - IPv6 Conf.</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="ipv6">
	<option value="0">Off</option>
	<option value="auto">SLAAC</option>
	<option value="dhcp">DHCPv6</option>
	<option value="prefix">Prefix</option>
	</select>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">
	On-boot CT?
	</td>
	<td class="fieldarea">
	<label class="checkbox-inline">
	<input type="checkbox" name="onboot" value="1" checked> Specifies whether a CT will be started during hypervisor boot-up. (Recommended)
	</label>
	</td>
	</tr>
	</table>

	<div class="btn-container">
	<input type="submit" class="btn btn-primary" value="Save Changes" name="addnewlxcplan" id="addnewlxcplan">
	<input type="reset" class="btn btn-default" value="Cancel Changes">
	</div>
	</form>
	';
}

// MODULE FORM: Edit an LXC Plan
function lxc_plan_edit($id) {
	$plan= Capsule::table('mod_pvewhmcs_plans')->where('id', '=', $id)->get()[0];
	if (empty($plan)) {
		echo 'Plan Not found' ;
		return false ;
	}
	echo '<pre>' ;
		//print_r($plan) ;
	echo '</pre>' ;

	echo '
	<form method="post">
	<table class="form" border="0" cellpadding="3" cellspacing="1" width="100%">
	<tr>
	<td class="fieldlabel">Plan Title</td>
	<td class="fieldarea">
	<input type="text" size="35" name="title" id="title" required value="'.$plan->title.'">
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Limit</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpulimit" id="cpulimit" value="'.$plan->cpulimit.'" required>
	Limit of CPU usage. Default is 1. Note: if the computer has 2 CPUs, it has total of "2" CPU time. Value "0" indicates no CPU limit.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Weighting</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpuunits" id="cpuunits" value="'.$plan->cpuunits.'" required>
	Number is relative to weights of all the other running VMs. 8 - 500000, recommend 1024.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">RAM - Memory</td>
	<td class="fieldarea">
	<input type="text" size="8" name="memory" id="memory" required value="'.$plan->memory.'">
	RAM space in Megabytes e.g 1024 = 1GB
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Swap - Space</td>
	<td class="fieldarea">
	<input type="text" size="8" name="swap" id="swap" value="'.$plan->swap.'">
	Swap space in Megabytes e.g 1024 = 1GB
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Capacity</td>
	<td class="fieldarea">
	<input type="text" size="8" name="disk" id="disk" value="'.$plan->disk.'" required>
	HDD/SSD storage space in Gigabytes e.g 1024 = 1TB
	</td>
	</tr>
 	<tr>
	<td class="fieldlabel">Disk - I/O Cap</td>
	<td class="fieldarea">
	<input type="text" size="8" name="diskio" id="diskio" value="'.$plan->diskio.'" required>
	Limit of Disk I/O in KiB/s. 0 for unrestricted storage access.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">PVE Store - Name</td>
	<td class="fieldarea">
	<input type="text" size="8" name="storage" id="storage" value="'.$plan->storage.'" required>
	Name of VM/CT Storage on Proxmox VE hypervisor. local/local-lvm/etc.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Interface</td>
	<td class="fieldarea">
	<input type="text" size="8" name="bridge" id="bridge" value="'.$plan->bridge.'">
	Network / Bridge / NIC name. PVE default bridge prefix is "vmbr".
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Bridge/NIC ID</td>
	<td class="fieldarea">
	<input type="text" size="8" name="vmbr" id="vmbr" value="'.$plan->vmbr.'">
	Interface ID. PVE Bridge default is 0, for "vmbr0". PVE SDN, leave blank.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - VLAN ID</td>
	<td class="fieldarea">
	<input type="text" size="8" name="vlanid" id="vlanid">
	VLAN ID for Plan Services. Default forgoes tagging (VLAN ID), blank for untagged.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Rate</td>
	<td class="fieldarea">
	<input type="text" size="8" name="netrate" id="netrate" value="'.$plan->netrate.'">
	Network Rate Limit in Megabit/Second. Zero for unlimited.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - BW Limit</td>
	<td class="fieldarea">
	<input type="text" size="8" name="bw" id="bw" value="'.$plan->bw.'">
	Monthly Bandwidth Limit in Gigabytes. Blank for unlimited.
	</td>
	</tr>
   	<tr>
	<td class="fieldlabel">Network - IPv6 Conf.</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="ipv6">
	<option value="0" '. ($plan->ipv6=="0" ? "selected" : "").'>Off</option>
	<option value="auto" '. ($plan->ipv6=="auto" ? "selected" : "").'>SLAAC</option>
	<option value="dhcp" '. ($plan->ipv6=="dhcp" ? "selected" : "").'>DHCPv6</option>
	<option value="prefix" '. ($plan->ipv6=="prefix" ? "selected" : "").'>Prefix</option>
	</select>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">
	On-boot CT?
	</td>
	<td class="fieldarea">
	<label class="checkbox-inline">
	<input type="checkbox" value="1" name="onboot" '. ($plan->onboot=="1" ? "checked" : "").'> Specifies whether a CT will be started during hypervisor boot-up. (Recommended)
	</label>
	</td>
	</tr>
	</table>

	<div class="btn-container">
	<input type="submit" class="btn btn-primary" value="Save Changes" name="updatelxcplan" id="updatelxcplan">
	<input type="reset" class="btn btn-default" value="Cancel Changes">
	</div>
	</form>
	';
}

// MODULE FORM ACTION: Save KVM Plan
function save_kvm_plan() {
	try {
		Capsule::connection()->transaction(
			function ($connectionManager)
			{
				/** @var \Illuminate\Database\Connection $connectionManager */
				$connectionManager->table('mod_pvewhmcs_plans')->insert(
					[
						'title' => $_POST['title'],
						'vmtype' => 'kvm',
						'ostype' => $_POST['ostype'],
						'cpus' => $_POST['cpus'],
						'cpuemu' => $_POST['cpuemu'],
						'cores' => $_POST['cores'],
						'cpulimit' => $_POST['cpulimit'],
						'cpuunits' => $_POST['cpuunits'],
						'memory' => $_POST['memory'],
						'balloon' => $_POST['balloon'],
						'disk' => $_POST['disk'],
						'diskformat' => $_POST['diskformat'],
						'diskcache' => $_POST['diskcache'],
						'disktype' => $_POST['disktype'],
						'diskio' => $_POST['diskio'],
						'storage' => $_POST['storage'],
						'netmode' => $_POST['netmode'],
						'bridge' => $_POST['bridge'],
						'vmbr' => $_POST['vmbr'],
						'netmodel' => $_POST['netmodel'],
						'vlanid' => $_POST['vlanid'],
						'netrate' => $_POST['netrate'],
						'bw' => $_POST['bw'],
						'ipv6' => $_POST['ipv6'],
						'kvm' => $_POST['kvm'],
						'onboot' => $_POST['onboot'],
					]
				);
			}
		);
		$_SESSION['pvewhmcs']['infomsg']['title']='QEMU Plan added.' ;
		$_SESSION['pvewhmcs']['infomsg']['message']='Saved the QEMU Plan successfully.' ;
		header("Location: ".pvewhmcs_BASEURL."&tab=vmplans&action=planlist");
	} catch (\Exception $e) {
		echo "Uh oh! Inserting didn't work, but I was able to rollback. {$e->getMessage()}";
	}
}

// MODULE FORM ACTION: Update KVM Plan
function update_kvm_plan() {
	Capsule::table('mod_pvewhmcs_plans')
	->where('id', $_GET['id'])
	->update(
		[
			'title' => $_POST['title'],
			'vmtype' => 'kvm',
			'ostype' => $_POST['ostype'],
			'cpus' => $_POST['cpus'],
			'cpuemu' => $_POST['cpuemu'],
			'cores' => $_POST['cores'],
			'cpulimit' => $_POST['cpulimit'],
			'cpuunits' => $_POST['cpuunits'],
			'memory' => $_POST['memory'],
			'balloon' => $_POST['balloon'],
			'disk' => $_POST['disk'],
			'diskformat' => $_POST['diskformat'],
			'diskcache' => $_POST['diskcache'],
			'disktype' => $_POST['disktype'],
			'diskio' => $_POST['diskio'],
			'storage' => $_POST['storage'],
			'netmode' => $_POST['netmode'],
			'bridge' => $_POST['bridge'],
			'vmbr' => $_POST['vmbr'],
			'netmodel' => $_POST['netmodel'],
			'vlanid' => $_POST['vlanid'],
			'netrate' => $_POST['netrate'],
			'bw' => $_POST['bw'],
			'ipv6' => $_POST['ipv6'],
			'kvm' => $_POST['kvm'],
			'onboot' => $_POST['onboot'],
		]
	);
	$_SESSION['pvewhmcs']['infomsg']['title']='QEMU Plan updated.' ;
	$_SESSION['pvewhmcs']['infomsg']['message']='Updated the QEMU Plan successfully. (Updating plans will not alter existing VMs)' ;
	header("Location: ".pvewhmcs_BASEURL."&tab=vmplans&action=planlist");
}

// MODULE FORM ACTION: Remove Plan
function remove_plan($id) {
	Capsule::table('mod_pvewhmcs_plans')->where('id', '=', $id)->delete();
	header("Location: ".pvewhmcs_BASEURL."&tab=vmplans&action=planlist");
	$_SESSION['pvewhmcs']['infomsg']['title']='Plan Deleted.' ;
	$_SESSION['pvewhmcs']['infomsg']['message']='Selected Item deleted successfully.' ;
}

// MODULE FORM ACTION: Save LXC Plan
function save_lxc_plan() {
	try {
		Capsule::connection()->transaction(
			function ($connectionManager)
			{
				/** @var \Illuminate\Database\Connection $connectionManager */
				$connectionManager->table('mod_pvewhmcs_plans')->insert(
					[
						'title' => $_POST['title'],
						'vmtype' => 'lxc',
						'cores' => $_POST['cores'],
						'cpulimit' => $_POST['cpulimit'],
						'cpuunits' => $_POST['cpuunits'],
						'memory' => $_POST['memory'],
						'swap' => $_POST['swap'],
						'disk' => $_POST['disk'],
						'diskio' => $_POST['diskio'],
						'storage' => $_POST['storage'],
						'bridge' => $_POST['bridge'],
						'vmbr' => $_POST['vmbr'],
						'netmodel' => $_POST['netmodel'],
						'vlanid' => $_POST['vlanid'],
						'netrate' => $_POST['netrate'],
						'bw' => $_POST['bw'],
						'ipv6' => $_POST['ipv6'],
						'onboot' => $_POST['onboot'],
					]
				);
			}
		);
		$_SESSION['pvewhmcs']['infomsg']['title']='New LXC Plan added.' ;
		$_SESSION['pvewhmcs']['infomsg']['message']='Saved the LXC Plan successfully.' ;
		header("Location: ".pvewhmcs_BASEURL."&tab=vmplans&action=planlist");
	} catch (\Exception $e) {
		echo "Uh oh! Inserting didn't work, but I was able to rollback. {$e->getMessage()}";
	}
}

// MODULE FORM ACTION: Update LXC Plan
function update_lxc_plan() {
	Capsule::table('mod_pvewhmcs_plans')
	->where('id', $_GET['id'])
	->update(
		[
			'title' => $_POST['title'],
			'vmtype' => 'lxc',
			'cores' => $_POST['cores'],
			'cpulimit' => $_POST['cpulimit'],
			'cpuunits' => $_POST['cpuunits'],
			'memory' => $_POST['memory'],
			'swap' => $_POST['swap'],
			'disk' => $_POST['disk'],
			'diskio' => $_POST['diskio'],
			'storage' => $_POST['storage'],
			'bridge' => $_POST['bridge'],
			'vmbr' => $_POST['vmbr'],
			'netmodel' => $_POST['netmodel'],
			'vlanid' => $_POST['vlanid'],
			'netrate' => $_POST['netrate'],
			'bw' => $_POST['bw'],
			'ipv6' => $_POST['ipv6'],
			'onboot' => $_POST['onboot'],
		]
	);
	$_SESSION['pvewhmcs']['infomsg']['title']='LXC Plan updated.' ;
	$_SESSION['pvewhmcs']['infomsg']['message']='Updated the LXC Plan successfully. (Updating plans will not alter existing CTs)' ;
	header("Location: ".pvewhmcs_BASEURL."&tab=vmplans&action=planlist");
}

// IP POOLS: List all Pools by type (ipv4 or ipv6)
function list_ip_pools($type = 'ipv4') {
    $poolTypeName = ($type == 'ipv6') ? 'IPv6' : 'IPv4';
    echo '<h2>Manage ' . $poolTypeName . ' Pools</h2>';
	echo '<a class="btn btn-primary" href="'. pvewhmcs_BASEURL .'&amp;tab=ippools&amp;action=new_ip_pool&amp;type='.$type.'"><i class="fa fa-plus-square"></i>&nbsp; New ' . $poolTypeName . ' Pool</a>';
	
	$pools = Capsule::table('mod_pvewhmcs_ip_pools')->where('pool_type', '=', $type)->get();
	
	if (count($pools) == 0) {
		echo '<p>No ' . $poolTypeName . ' pools found.</p>';
	} else {
		echo '<table class="datatable"><tr><th>ID</th><th>Pool Name</th><th>Gateway</th><th>Type</th><th>Action</th></tr>';
		foreach ($pools as $pool) {
			echo '<tr>';
			echo '<td>'.$pool->id . PHP_EOL .'</td>';
			echo '<td>'.htmlspecialchars($pool->title) . PHP_EOL .'</td>';
			echo '<td>'.htmlspecialchars($pool->gateway) . PHP_EOL .'</td>';
			echo '<td>'.strtoupper($pool->pool_type) . PHP_EOL .'</td>';
			echo '<td>
			<a href="'.pvewhmcs_BASEURL.'&amp;tab=ippools&amp;action=list_ips&amp;id='.$pool->id.'&amp;type='.$type.'"><img height="16" width="16" border="0" alt="List IPs" src="images/info.png"> List IPs</a>&nbsp;
			<a href="'.pvewhmcs_BASEURL.'&amp;tab=ippools&amp;action=removeippool&amp;id='.$pool->id.'&amp;type='.$type.'" onclick="return confirm(\'Pool and all IP Addresses assigned to it will be deleted, continue?\')"><img height="16" width="16" border="0" alt="Remove" src="images/delete.gif"></a>
			</td>' ;
			echo '</tr>' ;
		}
		echo '</table>';
	}
}

// IP POOL FORM: Add IP Pool (for a specific type)
function add_ip_pool($type = 'ipv4') {
    $poolTypeName = ($type == 'ipv6') ? 'IPv6' : 'IPv4';
    echo '<h2>Add New ' . $poolTypeName . ' Pool</h2>';
	echo '
	<form method="post">
	<input type="hidden" name="pool_type" value="'.htmlspecialchars($type).'">
	<table class="form" border="0" cellpadding="3" cellspacing="1" width="100%">
	<tr>
	<td class="fieldlabel">Pool Title</td>
	<td class="fieldarea">
	<input type="text" size="35" name="title" id="title" required>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Gateway Address</td>
	<td class="fieldarea">
	<input type="text" size="25" name="gateway" id="gateway" required>
	Enter a valid ' . $poolTypeName . ' gateway address.
	</td>
	</tr>
	</table>
	<input type="submit" class="btn btn-primary" name="newIPpool" value="Save Pool"/>
	</form>
	';
}

// IP POOL FORM ACTION: Save Pool
function save_ip_pool() {
    $title = $_POST['title'];
    $gateway = trim($_POST['gateway']);
    $pool_type = $_POST['pool_type']; // 'ipv4' or 'ipv6'

    // Validation
    $isValidGateway = false;
    if ($pool_type == 'ipv4') {
        $isValidGateway = filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    } elseif ($pool_type == 'ipv6') {
        $isValidGateway = filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
    }

    if (!$isValidGateway) {
        $_SESSION['pvewhmcs']['infomsg']['title'] = 'Error Saving IP Pool';
        $_SESSION['pvewhmcs']['infomsg']['message'] = 'Invalid ' . strtoupper($pool_type) . ' gateway address provided: ' . htmlspecialchars($gateway);
        header("Location: ".pvewhmcs_BASEURL."&tab=ippools&action=new_ip_pool&type=".$pool_type);
        exit;
    }

	try {
		Capsule::connection()->transaction(
			function ($connectionManager) use ($title, $gateway, $pool_type)
			{
				/** @var \Illuminate\Database\Connection $connectionManager */
				$connectionManager->table('mod_pvewhmcs_ip_pools')->insert(
					[
						'title' => $title,
						'gateway' => $gateway,
                        'pool_type' => $pool_type,
					]
				);
			}
		);
		$_SESSION['pvewhmcs']['infomsg']['title']='New ' . strtoupper($pool_type) . ' IP Pool added.' ;
		$_SESSION['pvewhmcs']['infomsg']['message']='New ' . strtoupper($pool_type) . ' IP Pool saved successfully.' ;
		header("Location: ".pvewhmcs_BASEURL."&tab=ippools&action=list_ip_pools&type=".$pool_type);
        exit;
	} catch (\Exception $e) {
        $_SESSION['pvewhmcs']['infomsg']['title'] = 'Error Saving IP Pool';
		$_SESSION['pvewhmcs']['infomsg']['message'] = "Uh oh! Inserting didn't work. {$e->getMessage()}";
        header("Location: ".pvewhmcs_BASEURL."&tab=ippools&action=new_ip_pool&type=".$pool_type);
        exit;
	}
}

// IP POOL FORM ACTION: Remove Pool
function removeIpPool($id) {
    // Before deleting the pool, get its type to redirect correctly
    $pool = Capsule::table('mod_pvewhmcs_ip_pools')->where('id', '=', $id)->first();
    $pool_type_for_redirect = $pool ? $pool->pool_type : 'ipv4'; // Default redirect if pool not found

	Capsule::table('mod_pvewhmcs_ip_addresses')->where('pool_id', '=', $id)->delete();
	Capsule::table('mod_pvewhmcs_ip_pools')->where('id', '=', $id)->delete();

	$_SESSION['pvewhmcs']['infomsg']['title']='IP Pool Deleted.' ;
	$_SESSION['pvewhmcs']['infomsg']['message']='Deleted the IP Pool successfully.' ;
	header("Location: ".pvewhmcs_BASEURL."&tab=ippools&action=list_ip_pools&type=".$pool_type_for_redirect);
    exit;
}

// IP POOL FORM ACTION: Add IP to Pool
// This function will need significant changes to handle pool_type for validation
// and to select the correct IP parsing libraries.
function add_ip_2_pool() {
    // Determine pool type from the selected pool_id
    $pool_id_for_adding = isset($_POST['pool_id']) ? $_POST['pool_id'] : (isset($_GET['pool_id']) ? $_GET['pool_id'] : null);
    $current_pool = null;
    if ($pool_id_for_adding) {
        $current_pool = Capsule::table('mod_pvewhmcs_ip_pools')->where('id', '=', $pool_id_for_adding)->first();
    }
    $pool_type_of_current_pool = $current_pool ? $current_pool->pool_type : null;


	require_once(ROOTDIR.'/modules/addons/pvewhmcs/Ipv4/Subnet.php');
	require_once(ROOTDIR.'/modules/addons/pvewhmcs/Ipv6/Address.php');
	require_once(ROOTDIR.'/modules/addons/pvewhmcs/Ipv6/Subnet.php');
	require_once(ROOTDIR.'/modules/addons/pvewhmcs/Ipv6/SubnetIterator.php');

    // Fetch pools based on type for the dropdown, or all if type isn't determined yet for the form.
    // For now, let's assume the form will be presented in context of a pool type or allow selection.
    // If this form is reached via "Add IP to this Pool" on a specific pool's IP list page,
    // then pool_id would be in GET.
    
    $gateways = []; // Reset gateways for this scope
    $pools_for_dropdown = [];
    if ($pool_type_of_current_pool) { // If we know the pool type (e.g. adding to a specific pool)
        $pools_for_dropdown = Capsule::table('mod_pvewhmcs_ip_pools')->where('pool_type', '=', $pool_type_of_current_pool)->get();
        if ($pool_id_for_adding && !$current_pool) { // If a pool_id was given but not found
             echo '<div class="errorbox">Error: The specified pool (ID: '.htmlspecialchars($pool_id_for_adding).') was not found or does not match the expected type.</div>';
             return;
        }
    } else { // Fallback: show all pools if type context is missing (less ideal)
        $pools_for_dropdown = Capsule::table('mod_pvewhmcs_ip_pools')->get();
    }


	echo '<form method="post" action="'.pvewhmcs_BASEURL.'&amp;tab=ippools&amp;action=newip">'; // Ensure action points correctly
    if ($pool_id_for_adding) {
        echo '<input type="hidden" name="pool_id_for_add" value="'.htmlspecialchars($pool_id_for_adding).'">';
    }
	echo '
	<table class="form" border="0" cellpadding="3" cellspacing="1" width="100%">
	<tr>
	<td class="fieldlabel">IP Pool</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="pool_id">';
	foreach ($pools_for_dropdown as $pool) {
        $selected = ($pool->id == $pool_id_for_adding) ? 'selected' : '';
		echo '<option value="'.$pool->id.'" '.$selected.'>'.htmlspecialchars($pool->title).' ('.strtoupper($pool->pool_type).')</option>';
		// Populate gateways array only for selected pool type if possible, or all for now
        $gateways[]=$pool->gateway; 
	}
	echo '</select>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">IP Block</td>
	<td class="fieldarea">
	<input type="text" name="ipblock"/>
	IP Block with CIDR e.g. 172.16.255.230/27 or 2001:db8::/48. For single IP, CIDR is optional (IPv4 assumes /32, IPv6 assumes /128).
	</td>
	</tr>
	</table>
	<input type="submit" name="assignIP2pool" value="Save"/>
	</form>';
	if (isset($_POST['assignIP2pool'])) {
		$ipblock = trim($_POST['ipblock']);
		$pool_id = $_POST['pool_id'];
		$successMessageTitle = 'IP Address/Blocks added to Pool.';
		$successMessage = 'You can remove IP Addresses from the pool.';
		$errorMessageTitle = 'Error adding IP Block';
		$errorMessage = '';

		try {
            // Retrieve the pool to determine its type for validation
            $pool_to_add_to = Capsule::table('mod_pvewhmcs_ip_pools')->where('id', '=', $pool_id)->first();
            if (!$pool_to_add_to) {
                throw new \Exception("Selected IP Pool (ID: $pool_id) not found.");
            }
            $pool_type = $pool_to_add_to->pool_type;
            $current_pool_gateway = $pool_to_add_to->gateway;

            // Determine IP block version
            $isIPv6Block = str_contains($ipblock, ':');

            // Explicit validation against pool type
            if ($pool_type == 'ipv4' && $isIPv6Block) {
                $_SESSION['pvewhmcs']['infomsg']['title'] = $errorMessageTitle;
                $_SESSION['pvewhmcs']['infomsg']['message'] = 'Validation Error: Cannot add an IPv6 block to an IPv4 pool.';
                header("Location: ".pvewhmcs_BASEURL."&tab=ippools&action=newip&pool_id=".$pool_id."&type=ipv4");
                exit;
            }
            if ($pool_type == 'ipv6' && !$isIPv6Block) {
                $_SESSION['pvewhmcs']['infomsg']['title'] = $errorMessageTitle;
                $_SESSION['pvewhmcs']['infomsg']['message'] = 'Validation Error: Cannot add an IPv4 block to an IPv6 pool.';
                header("Location: ".pvewhmcs_BASEURL."&tab=ippools&action=newip&pool_id=".$pool_id."&type=ipv6");
                exit;
            }

            // Proceed with parsing and adding if validation passes
			if ($pool_type == 'ipv6') { // This implies $isIPv6Block is true due to above validation
				if (!str_contains($ipblock, '/')) {
					$ipblock .= '/128';
				}
				$subnet = \PveWhmcs\Ipv6\Subnet::fromString($ipblock);
				$iterator = new \PveWhmcs\Ipv6\SubnetIterator($subnet);
				$prefix = $subnet->getPrefix();

				foreach ($iterator as $ipAddress) {
					$ipString = $ipAddress->toString();
					if ($ipString != $current_pool_gateway) { 
						Capsule::table('mod_pvewhmcs_ip_addresses')->insert(
							[
								'pool_id' => $pool_id,
								'ipaddress' => $ipString,
								'mask' => $prefix, // Store prefix for IPv6
							]
						);
					}
				}
				$successMessageTitle = 'IPv6 Address/Blocks added to Pool.';
			} else { // This implies $pool_type is 'ipv4' and $isIPv6Block is false
				if (!str_contains($ipblock, '/')) {
					if ($ipblock != $current_pool_gateway) {
						Capsule::table('mod_pvewhmcs_ip_addresses')->insert(
							[
								'pool_id' => $pool_id,
								'ipaddress' => $ipblock,
								'mask' => '255.255.255.255', 
							]
						);
					}
				} else {
					$subnet = Ipv4_Subnet::fromString($ipblock);
					$ips = $subnet->getIterator();
					foreach ($ips as $ip) {
						if ($ip != $current_pool_gateway) {
							Capsule::table('mod_pvewhmcs_ip_addresses')->insert(
								[
									'pool_id' => $pool_id,
									'ipaddress' => $ip,
									'mask' => $subnet->getNetmask(),
								]
							);
						}
					}
				}
				$successMessageTitle = 'IPv4 Address/Blocks added to Pool.';
			}
			$_SESSION['pvewhmcs']['infomsg']['title'] = $successMessageTitle;
			$_SESSION['pvewhmcs']['infomsg']['message'] = $successMessage;

		} catch (\Exception $e) {
			$_SESSION['pvewhmcs']['infomsg']['title'] = $errorMessageTitle;
			$_SESSION['pvewhmcs']['infomsg']['message'] = 'Failed to add IP block: ' . $e->getMessage();
		}
        // Redirect back to the list of IPs for the specific pool
		header("Location: ".pvewhmcs_BASEURL."&tab=ippools&action=list_ips&id=".$pool_id."&type=".($pool_type ?: 'ipv4'));
		exit; 
	}
}

// IP POOL FORM: List IPs in Pool
function list_ips() {
    $pool_id = $_GET['id'];
    $pool = Capsule::table('mod_pvewhmcs_ip_pools')->where('id', '=', $pool_id)->first();

    if (!$pool) {
        echo '<div class="errorbox">Error: IP Pool not found.</div>';
        return;
    }
    $pool_type = $pool->pool_type;
    $poolTypeName = ($pool_type == 'ipv6') ? 'IPv6' : 'IPv4';

    echo '<h2>IP Addresses in Pool: ' . htmlspecialchars($pool->title) . ' (' . $poolTypeName . ')</h2>';
    // Link to add IPs to *this* specific pool
    echo '<a class="btn btn-primary" href="'. pvewhmcs_BASEURL .'&amp;tab=ippools&amp;action=newip&amp;pool_id='.$pool_id.'&amp;type='.$pool_type.'"><i class="fa fa-plus"></i>&nbsp; Add IP(s) to this ' . $poolTypeName . ' Pool</a>';


	echo '<table class="datatable"><tr><th>IP Address</th><th>Subnet Mask/Prefix</th><th>Action</th></tr>' ;
	$ips_in_pool = Capsule::table('mod_pvewhmcs_ip_addresses')->where('pool_id', '=', $pool_id)->get();

    if (count($ips_in_pool) == 0) {
        echo '<tr><td colspan="3">No IP addresses found in this pool.</td></tr>';
    } else {
        foreach ($ips_in_pool as $ip) {
            $displayIp = htmlspecialchars($ip->ipaddress);
            $displayMaskOrPrefix = htmlspecialchars($ip->mask);
            
            if ($pool_type == 'ipv6') { // For IPv6, mask is the prefix
                $displayIp .= '/' . $displayMaskOrPrefix;
                $displayMaskOrPrefix = ''; // Clear if shown with IP, or adjust as needed
            }
            
            echo '<tr><td>'.$displayIp.'</td><td>'.$displayMaskOrPrefix.'</td><td>';
            if (count(Capsule::table('mod_pvewhmcs_vms')->where('ipaddress','=',$ip->ipaddress)->get())>0)
                echo 'In use' ;
            else
                echo '<a href="'.pvewhmcs_BASEURL.'&amp;tab=ippools&amp;action=removeip&amp;pool_id='.$ip->pool_id.'&amp;id='.$ip->id.'&amp;type='.$pool_type.'" onclick="return confirm(\'IP Address will be deleted from the pool, continue?\')"><img height="16" width="16" border="0" alt="Remove IP" src="images/delete.gif"></a>';
            echo '</td></tr>';
        }
    }
	echo '</table>' ;
}

// IP POOL FORM ACTION: Remove IP from Pool
function removeip($id, $pool_id) {
    // Get pool type for redirect
    $pool = Capsule::table('mod_pvewhmcs_ip_pools')->where('id', '=', $pool_id)->first();
    $pool_type_for_redirect = $pool ? $pool->pool_type : (isset($_GET['type']) ? $_GET['type'] : 'ipv4');

	Capsule::table('mod_pvewhmcs_ip_addresses')->where('id', '=', $id)->delete();
	$_SESSION['pvewhmcs']['infomsg']['title']='IP Address deleted.' ;
	$_SESSION['pvewhmcs']['infomsg']['message']='Deleted selected item successfully.' ;
	header("Location: ".pvewhmcs_BASEURL."&tab=ippools&action=list_ips&id=".$pool_id."&type=".$pool_type_for_redirect);
    exit;
}
?>
