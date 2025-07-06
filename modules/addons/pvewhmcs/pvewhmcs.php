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
		if ($i<$query_count-1)
			if (!Capsule::statement($query.';'))
		$err=true;
		$i++ ;
	}
	// Return success or error.
	if (!$err)
		return array('status'=>'success','description'=>'Proxmox VE for WHMCS was installed successfully!');

	return array('status'=>'error','description'=>'Proxmox VE for WHMCS was not activated properly.');

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
	<li class="'.($_GET['tab']=="ippools" ? "active" : "").'"><a id="tabLink2" data-toggle="tab" role="tab" href="#ippools">IPv4 Pools</a></li>
	<li class="'.($_GET['tab']=="nodes" ? "active" : "").'"><a id="tabLink3" data-toggle="tab" role="tab" href="#nodes">Nodes / Cluster</a></li>
	<li class="'.($_GET['tab']=="actions" ? "active" : "").'"><a id="tabLink4" data-toggle="tab" role="tab" href="#actions">Actions / Logs</a></li>
	<li class="'.($_GET['tab']=="health" ? "active" : "").'"><a id="tabLink5" data-toggle="tab" role="tab" href="#health">Support / Health</a></li>
	<li class="'.($_GET['tab']=="config" ? "active" : "").'"><a id="tabLink6" data-toggle="tab" role="tab" href="#config">Module Config</a></li>
        <li class="'.(isset($_GET['tab']) && $_GET['tab']=="vms" ? "active" : "").'"><a id="tabLink7" href="addonmodules.php?module=pvewhmcs&tab=vms">Virtual Machines</a></li>
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

	echo '
	<div id="ippools" class="tab-pane '.($_GET['tab']=="ippools" ? "active" : "").'" >
	<div class="btn-group">
	<a class="btn btn-default" href="'. pvewhmcs_BASEURL .'&amp;tab=ippools&amp;action=list_ip_pools">
	<i class="fa fa-list"></i>&nbsp; List: IPv4 Pools
	</a>
	<a class="btn btn-default" href="'. pvewhmcs_BASEURL .'&amp;tab=ippools&amp;action=newip">
	<i class="fa fa-plus"></i>&nbsp; Add: IPv4 to Pool
	</a>
	</div>
	';
	if ($_GET['action']=='list_ip_pools') {
		list_ip_pools() ;
	}
	if ($_GET['action']=='new_ip_pool') {
		add_ip_pool() ;
	}
	if ($_GET['action']=='newip') {
		add_ip_2_pool() ;
	}
	if (isset($_POST['newIPpool'])) {
		save_ip_pool() ;
	}
	if ($_GET['action']=='removeippool') {
		removeIpPool($_GET['id']) ;
	}
	if ($_GET['action']=='list_ips') {
		list_ips();
	}
	if ($_GET['action']=='removeip') {
		removeip($_GET['id'],$_GET['pool_id']);
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

	// Virtual Machines Tab Content
	echo '<div id="vms" class="tab-pane '.($_GET['tab']=="vms" ? "active" : "").'" >' ;
	if ($_GET['tab'] == "vms") {
	    // Display any action messages
	    if (isset($_SESSION['pvewhmcs_admin_action_msg'])) {
	        echo $_SESSION['pvewhmcs_admin_action_msg'];
	        unset($_SESSION['pvewhmcs_admin_action_msg']);
	    }

	    $vm_action = isset($_GET['vm_action']) ? $_GET['vm_action'] : '';
	    $vmid = isset($_GET['vmid']) ? $_GET['vmid'] : null;
	    $node = isset($_GET['node']) ? $_GET['node'] : null; 
	    $vtype = isset($_GET['vtype']) ? $_GET['vtype'] : null; 

	    $action_params = ['vmid' => $vmid, 'node' => $node, 'vtype' => $vtype];

	    switch ($vm_action) {
	        case 'admin_vm_start':
	            pvewhmcs_admin_vm_start($action_params);
	            break;
	        case 'admin_vm_stop':
	            pvewhmcs_admin_vm_stop($action_params);
	            break;
	        case 'admin_vm_shutdown':
	            pvewhmcs_admin_vm_shutdown($action_params);
	            break;
	        case 'admin_vm_reboot':
	            pvewhmcs_admin_vm_reboot($action_params);
	            break;
	        case 'view_detail':
	            if (!empty($vmid)) {
	                pvewhmcs_view_vm_detail_admin($action_params); 
	            } else {
	                pvewhmcs_list_vms_admin(); 
	            }
	            break;
	        default:
	            pvewhmcs_list_vms_admin();
	            break;
	    }
	}
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
	<td class="fieldlabel">RAM - Swap</td>
	<td class="fieldarea">
	<input type="text" size="8" name="swap" id="swap" value="512"> MB (Typically handled by Guest OS for KVM. Informational or for future cloud-init use)
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Display - VGA Type</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="vga">
	<option value="std" selected>Standard VGA</option>
	<option value="vmware">VMware Compatible</option>
	<option value="qxl">QXL (SPICE)</option>
	<option value="none">None</option>
	</select>
	Type of virtual graphics card.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Display - VGA Memory (MB)</td>
	<td class="fieldarea">
	<input type="number" size="8" name="vgpu_memory" id="vgpu_memory" value="16" min="4" max="512"> MB (Proxmox default is 16MB for std. Max 16MB was mentioned by user, API allows more.)
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
	<td class="fieldlabel">RAM - Swap</td>
	<td class="fieldarea">
	<input type="text" size="8" name="swap" id="swap" value="'.$plan->swap.'"> MB (Typically handled by Guest OS for KVM. Informational or for future cloud-init use)
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Display - VGA Type</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="vga">
	<option value="std" ' . ($plan->vga == "std" ? "selected" : "") . '>Standard VGA</option>
	<option value="vmware" ' . ($plan->vga == "vmware" ? "selected" : "") . '>VMware Compatible</option>
	<option value="qxl" ' . ($plan->vga == "qxl" ? "selected" : "") . '>QXL (SPICE)</option>
	<option value="none" ' . ($plan->vga == "none" ? "selected" : "") . '>None</option>
	</select>
	Type of virtual graphics card.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Display - VGA Memory (MB)</td>
	<td class="fieldarea">
	<input type="number" size="8" name="vgpu_memory" id="vgpu_memory" value="'.$plan->vgpu_memory.'" min="4" max="512"> MB (Proxmox default is 16MB for std. Max 16MB was mentioned by user, API allows more)
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
						'swap' => $_POST['swap'],
						'vga' => $_POST['vga'],
						'vgpu_memory' => $_POST['vgpu_memory'],
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
			'swap' => $_POST['swap'],
			'vga' => $_POST['vga'],
			'vgpu_memory' => $_POST['vgpu_memory'],
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

// IP POOLS: List all Pools
function list_ip_pools() {
	echo '<a class="btn btn-default" href="'. pvewhmcs_BASEURL .'&amp;tab=ippools&amp;action=new_ip_pool"><i class="fa fa-plus-square"></i>&nbsp; New IPv4 Pool</a>';
	echo '<table class="datatable"><tr><th>ID</th><th>Pool</th><th>Gateway</th><th>Action</th></tr>';
	foreach (Capsule::table('mod_pvewhmcs_ip_pools')->get() as $pool) {
		echo '<tr>';
		echo '<td>'.$pool->id . PHP_EOL .'</td>';
		echo '<td>'.$pool->title . PHP_EOL .'</td>';
		echo '<td>'.$pool->gateway . PHP_EOL .'</td>';
		echo '<td>
		<a href="'.pvewhmcs_BASEURL.'&amp;tab=ippools&amp;action=list_ips&amp;id='.$pool->id.'"><img height="16" width="16" border="0" alt="Info" src="images/edit.gif"></a>
		<a href="'.pvewhmcs_BASEURL.'&amp;tab=ippools&amp;action=removeippool&amp;id='.$pool->id.'" onclick="return confirm(\'Pool and all IPv4 Addresses assigned to it will be deleted, continue?\')"><img height="16" width="16" border="0" alt="Remove" src="images/delete.gif"></a>
		</td>' ;
		echo '</tr>' ;
	}
	echo '</table>';
}

// IP POOL FORM: Add IP Pool
function add_ip_pool() {
	echo '
	<form method="post">
	<table class="form" border="0" cellpadding="3" cellspacing="1" width="100%">
	<tr>
	<td class="fieldlabel">Pool Title</td>
	<td class="fieldarea">
	<input type="text" size="35" name="title" id="title" required>
	</td>
	<td class="fieldlabel">IPv4 Gateway</td>
	<td class="fieldarea">
	<input type="text" size="25" name="gateway" id="gateway" required>
	Gateway address of the pool
	</td>
	</tr>
	</table>
	<input type="submit" class="btn btn-primary" name="newIPpool" value="Save"/>
	</form>
	';
}

// IP POOL FORM ACTION: Save Pool
function save_ip_pool() {
	try {
		Capsule::connection()->transaction(
			function ($connectionManager)
			{
				/** @var \Illuminate\Database\Connection $connectionManager */
				$connectionManager->table('mod_pvewhmcs_ip_pools')->insert(
					[
						'title' => $_POST['title'],
						'gateway' => $_POST['gateway'],
					]
				);
			}
		);
		$_SESSION['pvewhmcs']['infomsg']['title']='New IPv4 Pool added.' ;
		$_SESSION['pvewhmcs']['infomsg']['message']='New IPv4 Pool saved successfully.' ;
		header("Location: ".pvewhmcs_BASEURL."&tab=ippools&action=list_ip_pools");
	} catch (\Exception $e) {
		echo "Uh oh! Inserting didn't work, but I was able to rollback. {$e->getMessage()}";
	}
}

// IP POOL FORM ACTION: Remove Pool
function removeIpPool($id) {
	Capsule::table('mod_pvewhmcs_ip_addresses')->where('pool_id', '=', $id)->delete();
	Capsule::table('mod_pvewhmcs_ip_pools')->where('id', '=', $id)->delete();

	header("Location: ".pvewhmcs_BASEURL."&tab=ippools&action=list_ip_pools");
	$_SESSION['pvewhmcs']['infomsg']['title']='IPv4 Pool Deleted.' ;
	$_SESSION['pvewhmcs']['infomsg']['message']='Deleted the IPv4 Pool successfully.' ;
}

// IP POOL FORM ACTION: Add IP to Pool
function add_ip_2_pool() {
	require_once(ROOTDIR.'/modules/addons/pvewhmcs/Ipv4/Subnet.php');
	echo '<form method="post">
	<table class="form" border="0" cellpadding="3" cellspacing="1" width="100%">
	<tr>
	<td class="fieldlabel">IP Pool</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="pool_id">';
	foreach (Capsule::table('mod_pvewhmcs_ip_pools')->get() as $pool) {
		echo '<option value="'.$pool->id.'">'.$pool->title.'</option>';
		$gateways[]=$pool->gateway ;
	}
	echo '</select>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">IP Block</td>
	<td class="fieldarea">
	<input type="text" name="ipblock"/>
	IP Block with CIDR e.g. 172.16.255.230/27, or for single IP address don\'t use CIDR
	</td>
	</tr>
	</table>
	<input type="submit" name="assignIP2pool" value="Save"/>
	</form>';
	if (isset($_POST['assignIP2pool'])) {
			// check if single IP address
		if ((strpos($_POST['ipblock'],'/'))!=false) {
			$subnet=Ipv4_Subnet::fromString($_POST['ipblock']);
			$ips = $subnet->getIterator();
			foreach($ips as $ip) {
				if (!in_array($ip, $gateways)) {
					Capsule::table('mod_pvewhmcs_ip_addresses')->insert(
						[
							'pool_id' => $_POST['pool_id'],
							'ipaddress' => $ip,
							'mask' => $subnet->getNetmask(),
						]
					);
				}
			}
		}
		else {
			if (!in_array($_POST['ipblock'], $gateways)) {
				Capsule::table('mod_pvewhmcs_ip_addresses')->insert(
					[
						'pool_id' => $_POST['pool_id'],
						'ipaddress' => $_POST['ipblock'],
						'mask' => '255.255.255.255',
					]
				);
			}
		}
		header("Location: ".pvewhmcs_BASEURL."&tab=ippools&action=list_ips&id=".$_POST['pool_id']);
		$_SESSION['pvewhmcs']['infomsg']['title']='IPv4 Address/Blocks added to Pool.' ;
		$_SESSION['pvewhmcs']['infomsg']['message']='You can remove IPv4 Addresses from the pool.' ;
	}
}

// IP POOL FORM: List IPs in Pool
function list_ips() {
		//echo '<script>$(function() {$( "#dialog" ).dialog();});</script>' ;
		//echo '<div id="dialog">' ;
	echo '<table class="datatable"><tr><th>IP Address</th><th>Subnet Mask</th><th>Action</th></tr>' ;
	foreach (Capsule::table('mod_pvewhmcs_ip_addresses')->where('pool_id', '=', $_GET['id'])->get() as $ip) {
		echo '<tr><td>'.$ip->ipaddress.'</td><td>'.$ip->mask.'</td><td>';
		if (count(Capsule::table('mod_pvewhmcs_vms')->where('ipaddress','=',$ip->ipaddress)->get())>0)
			echo 'is in use' ;
		else
			echo '<a href="'.pvewhmcs_BASEURL.'&amp;tab=ippools&amp;action=removeip&amp;pool_id='.$ip->pool_id.'&amp;id='.$ip->id.'" onclick="return confirm(\'IPv4 Address will be deleted from the pool, continue?\')"><img height="16" width="16" border="0" alt="Edit" src="images/delete.gif"></a>';
		echo '</td></tr>';
	}
	echo '</table>' ;

}

// IP POOL FORM ACTION: Remove IP from Pool
function removeip($id,$pool_id) {
	Capsule::table('mod_pvewhmcs_ip_addresses')->where('id', '=', $id)->delete();
	header("Location: ".pvewhmcs_BASEURL."&tab=ippools&action=list_ips&id=".$pool_id);
	$_SESSION['pvewhmcs']['infomsg']['title']='IPv4 Address deleted.' ;
	$_SESSION['pvewhmcs']['infomsg']['message']='Deleted selected item successfully.' ;
}

function isHostUp($host, $port = 8006, $timeout = 5) {
    $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($connection) {
        fclose($connection);
        return true;
    }
    return false;
}

// New helper function to extract only relevant network data from a full RRD dataset.
// It looks for 'time', 'netin', and 'netout' keys in each data point.
if (!function_exists('pvewhmcs_extract_network_rrd_data')) {
    function pvewhmcs_extract_network_rrd_data($full_rrd_array) {
        $network_data_points = [];
        if (empty($full_rrd_array) || !is_array($full_rrd_array)) {
            return $network_data_points; // Return empty if input is not usable
        }

        foreach ($full_rrd_array as $data_point) {
            if (isset($data_point['time']) && isset($data_point['netin']) && isset($data_point['netout'])) {
                $network_data_points[] = [
                    'time'   => $data_point['time'],
                    'netin'  => $data_point['netin'],
                    'netout' => $data_point['netout'],
                ];
            } elseif (isset($data_point['time']) && (!isset($data_point['netin']) || !isset($data_point['netout'])) ) {
                // If time is present but netin/netout are missing for a point,
                // we can add it with 0 values or skip. Skipping is safer to avoid miscalculation.
                // For calculation, points missing these values are not useful.
                // However, to ensure the calculate function gets all *time* entries if that's important for its logic:
                // For now, we only include points that have all three.
                 if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
                    // Log that a data point was skipped due to missing netin/netout
                    // logModuleCall('pvewhmcs_rrd_extract', "Skipped RRD data point due to missing netin/netout, time: " . $data_point['time'], $data_point, '');
                }
            }
        }
        return $network_data_points;
    }
}



// ADMIN ADDON: List Virtual Machines
function pvewhmcs_list_vms_admin() {
	echo '<h3>Managed Virtual Machines</h3>';

	$vms_data = [];
	try {
		$managed_vms = Capsule::table('mod_pvewhmcs_vms as mvm')
			->join('tblhosting as h', 'mvm.id', '=', 'h.id') // Assuming mvm.id is the serviceid
			->join('tblproducts as p', 'h.packageid', '=', 'p.id')
			->join('tblclients as c', 'h.userid', '=', 'c.id')
			->join('tblservers as s', 'h.server', '=', 's.id')
			->where('s.type', '=', 'pvewhmcs') // Ensure we are only getting VMs from Proxmox servers
			->select(
				'mvm.id as vmid', // serviceid is the vmid in this context
				'mvm.vtype',
				'mvm.ipaddress as vm_main_ip',
				'h.domainstatus as whmcs_service_status',
				'h.server as server_id',
				'p.name as product_name',
				'c.firstname',
				'c.lastname',
				'c.companyname',
				's.ipaddress as server_ip',
				's.username as server_username',
				's.password as server_password_encrypted'
			)
			->get();

		if (count($managed_vms) == 0) {
			echo '<p>No Proxmox virtual machines found managed by this module.</p>';
			return;
		}

		echo '<table class="datatable" width="100%">
				<thead>
					<tr>
						<th>VMID</th>
						<th>Product Name</th>
						<th>Client</th>
						<th>PVE Status</th>
						<th>WHMCS Status</th>
						<th>IP Address</th>
						<th>Guest Agent</th>
						<th>Node</th>
						<th>Traffic In (Month)</th>
						<th>Traffic Out (Month)</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>';


		$port = 8006; // Proxmox default API port


		foreach ($managed_vms as $vm) {

			if (!isHostUp($vm->server_ip, $port)) {
			    // Host is down, handle accordingly
			    error_log(sprintf("Proxmox host %s is unreachable on port %s.", $vm->server_ip, $port));
			    return;
			    // Optionally return an error or skip the operation
			}


			// Decrypt server password
			$serverpassword_decrypted = localAPI('DecryptPassword', ['password2' => $vm->server_password_encrypted]);
			$serverpassword = $serverpassword_decrypted['password'];

			$pve_status = 'N/A';
			$guest_agent_status = 'N/A';
			$pve_node = 'N/A';
			$monthly_traffic_in = 'N/A';
			$monthly_traffic_out = 'N/A';

			try {
			        $proxmox = new PVE2_API($vm->server_ip, $vm->server_username, "pam", $serverpassword);

			        if ($proxmox->login()) {
			                $nodes = $proxmox->get_node_list();

			                $vm_resource_info = null;
			                $cluster_resources = $proxmox->get("/cluster/resources?type=vm");
			                foreach ($cluster_resources as $resource) {
			                        if ($resource['vmid'] == $vm->vmid && $resource['type'] == $vm->vtype) {
			                                $vm_resource_info = $resource;
			                                $pve_node = $resource['node'];
			                                break;
			                        }
			                }

			                if ($pve_node !== 'N/A' && $vm_resource_info) {
			                        $pve_status = ucfirst($vm_resource_info['status'] ?? 'unknown');
			                        if ($vm->vtype == 'qemu') {
			                                // Only wrap the config/agent API calls in a try/catch
			                                try {
			                                        $vm_config = $proxmox->get("/nodes/{$pve_node}/qemu/{$vm->vmid}/config");
			                                        if (isset($vm_config['agent']) && preg_match('/1/', $vm_config['agent'])) {
			                                                $agent_info = $proxmox->post("/nodes/{$pve_node}/qemu/{$vm->vmid}/agent/ping", []);
			                                                if (isset($agent_info) && is_array($agent_info) && empty($agent_info['errors']) && $agent_info !== null) {
			                                                        $guest_agent_status = '<span style="color:green;">Running</span>';
			                                                } else {
			                                                        $guest_agent_status = '<span style="color:orange;">Enabled, Not Responding</span>';
			                                                }
			                                        } else {
			                                                $guest_agent_status = '<span style="color:red;">Disabled</span>';
			                                        }
			                                } catch (Exception $e) {
			                                        $guest_agent_status = '<span style="color:red;">API Error</span>';
			                                        if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
			                                                logModuleCall('pvewhmcs_admin_list_vms', "PVE API Error for VMID {$vm->vmid} (agent/config)", $e->getMessage(), $e->getTraceAsString());
			                                        }
			                                }
			                        } else { // LXC
			                                $guest_agent_status = 'N/A (LXC)';
			                        }

			                        // Fetch and calculate monthly traffic data if node is known
			                        if ($pve_node !== 'N/A' && $pve_node !== 'Unknown' && $vm_resource_info && $vm_resource_info['status'] !== 'unknown') {
			                            try {
			                                // Attempt to get RRD data for 'netin' and 'netout' for the current month.
			                                // The PVE2_API get method fetches the data, which is then processed.
			                                // Reverted: Removed &ds=netin,netout to get full dataset for now
			                                $rrd_traffic_data_full = $proxmox->get("/nodes/{$pve_node}/{$vm->vtype}/{$vm->vmid}/rrddata?timeframe=month");

			                                if ($rrd_traffic_data_full && is_array($rrd_traffic_data_full)) {
			                                    // Extract only network-relevant data points
			                                    $network_rrd_points = pvewhmcs_extract_network_rrd_data($rrd_traffic_data_full);

			                                    if (empty($network_rrd_points)) {
			                                        // If, after extraction, there are no points with netin/netout, it's effectively no data.
			                                        $monthly_traffic_in = '<span style="color:orange;" title="No netin/netout data found in RRD output.">Data N/A</span>';
			                                        $monthly_traffic_out = $monthly_traffic_in;
			                                        if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
			                                            logModuleCall('pvewhmcs_admin_list_vms_traffic', "Extracted RRD network points are empty for VMID {$vm->vmid}", $rrd_traffic_data_full, '');
			                                        }
			                                    } else {
			                                        // Calculate totals from the extracted network RRD data array.
			                                        $traffic_totals = pvewhmcs_calculate_monthly_traffic_from_rrd($network_rrd_points);
			                                        if ($traffic_totals['error']) {
			                                            // Display error from calculation (e.g., counter reset, no data)
			                                            $monthly_traffic_in = '<span style="color:orange;" title="' . htmlentities($traffic_totals['error']) . '">Calc Err</span>';
			                                            $monthly_traffic_out = $monthly_traffic_in;
			                                            if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
			                                                logModuleCall('pvewhmcs_admin_list_vms_traffic', "RRD Traffic Calc Error VMID {$vm->vmid}: " . $traffic_totals['error'], $network_rrd_points, '');
			                                            }
			                                        } else {
			                                            // Format calculated bytes into readable string (GB, TB etc.)
			                                            $monthly_traffic_in = pvewhmcs_format_bytes($traffic_totals['in']);
			                                            $monthly_traffic_out = pvewhmcs_format_bytes($traffic_totals['out']);
			                                        }
			                                    }
			                                } else {
			                                    // RRD data fetch failed or returned empty/invalid
			                                    $monthly_traffic_in = '<span style="color:red;">RRD N/A</span>';
			                                        $monthly_traffic_out = $monthly_traffic_in;
			                                        if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
			                                            logModuleCall('pvewhmcs_admin_list_vms_traffic', "RRD Traffic Calc Error VMID {$vm->vmid}: " . $traffic_totals['error'], $rrd_traffic_data, '');
			                                        }
			                                    } else {
			                                        // Format calculated bytes into readable string (GB, TB etc.)
			                                        $monthly_traffic_in = pvewhmcs_format_bytes($traffic_totals['in']);
			                                        $monthly_traffic_out = pvewhmcs_format_bytes($traffic_totals['out']);
			                                    }
			                                } else {
			                                    // RRD data fetch failed or returned empty/invalid
			                                    $monthly_traffic_in = '<span style="color:red;">RRD N/A</span>';
			                                    $monthly_traffic_out = $monthly_traffic_in;
			                                    if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
			                                        logModuleCall('pvewhmcs_admin_list_vms_traffic', "RRD Fetch Failed/Empty for VMID {$vm->vmid}", $rrd_traffic_data, '');
			                                    }
			                                }
			                            } catch (Exception $e_rrd) {
			                                // Exception during Proxmox API call for RRD data
			                                $monthly_traffic_in = '<span style="color:red;">RRD API Err</span>';
			                                $monthly_traffic_out = $monthly_traffic_in;
			                                if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
			                                    logModuleCall('pvewhmcs_admin_list_vms_traffic', "RRD API Exception for VMID {$vm->vmid}", $e_rrd->getMessage(), $e_rrd->getTraceAsString());
			                                }
			                            }
			                        } else {
			                             // Conditions not met for fetching traffic data (e.g., VM not running, node unknown)
			                             $monthly_traffic_in = 'N/A';
			                             $monthly_traffic_out = 'N/A';
			                        }
			                } else {
			                        // VM not found on Proxmox cluster via /cluster/resources
			                        $pve_status = '<span style="color:red;">Not Found on PVE</span>';
			                        $pve_node = 'Unknown';
			                        $monthly_traffic_in = 'N/A';
			                        $monthly_traffic_out = 'N/A';
			                }
			        } else {
			                $pve_status = '<span style="color:red;">Login Failed</span>';
			                $monthly_traffic_in = 'N/A';
			                $monthly_traffic_out = 'N/A';
			        }
			} catch (Exception $e) {
			        $pve_status = '<span style="color:red;">API Error</span>';
			        $monthly_traffic_in = 'N/A';
			        $monthly_traffic_out = 'N/A';
			        if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
			                logModuleCall('pvewhmcs_admin_list_vms', "PVE API Error for VMID {$vm->vmid}", $e->getMessage(), $e->getTraceAsString());
			        }
			}                                                      




			$client_name = trim($vm->firstname . ' ' . $vm->lastname);
			if (!empty($vm->companyname)) {
				$client_name .= ' (' . $vm->companyname . ')';
			}
			
			// Action buttons - URLs will be placeholders for now
			$actions = '';
			if ($pve_status !== 'N/A' && strpos($pve_status, 'Error') === false && strpos($pve_status, 'Failed') === false && strpos($pve_status, 'Not Found') === false) {
				$base_action_url = pvewhmcs_BASEURL . "&tab=vms&vmid={$vm->vmid}&node={$pve_node}&vtype={$vm->vtype}";
				if ($pve_status == 'Stopped') {
					$actions .= "<a href='{$base_action_url}&vm_action=admin_vm_start' class='btn btn-xs btn-success'>Start</a> ";
				} else if ($pve_status == 'Running') {
					$actions .= "<a href='{$base_action_url}&vm_action=admin_vm_stop' class='btn btn-xs btn-warning'>Stop</a> ";
					$actions .= "<a href='{$base_action_url}&vm_action=admin_vm_shutdown' class='btn btn-xs btn-info'>Shutdown</a> ";
					$actions .= "<a href='{$base_action_url}&vm_action=admin_vm_reboot' class='btn btn-xs btn-primary'>Reboot</a> ";
				}
			}
			$actions .= "<a href='".pvewhmcs_BASEURL."&tab=vms&vm_action=view_detail&vmid={$vm->vmid}&node={$pve_node}&vtype={$vm->vtype}' class='btn btn-xs btn-default'>Details</a>";


			echo "<tr>
					<td>{$vm->vmid}</td>
					<td>{$vm->product_name}</td>
					<td><a href='clientssummary.php?userid={$h->userid}'>{$client_name}</a></td>
					<td>{$pve_status}</td>
					<td>{$vm->whmcs_service_status}</td>
					<td>{$vm->vm_main_ip}</td>
					<td>{$guest_agent_status}</td>
					<td>{$pve_node}</td>
					<td>{$monthly_traffic_in}</td>
					<td>{$monthly_traffic_out}</td>
					<td>{$actions}</td>
				  </tr>";
		}
		echo '</tbody></table>';

	} catch (Exception $e) {
		echo "<div class='alert alert-danger'>An error occurred while fetching VM list: " . $e->getMessage() . "</div>";
		if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
			logModuleCall('pvewhmcs_admin_list_vms', "General Error", $e->getMessage(), $e->getTraceAsString());
		}
	}
}


// Helper function to format bytes into a readable format (KB, MB, GB, TB)
// Used for displaying network traffic in a user-friendly way.
if (!function_exists('pvewhmcs_format_bytes')) {
    function pvewhmcs_format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        // Handle case where $bytes is 0 to avoid division by zero in log(1024) if $pow becomes very small.
        if ($bytes == 0) {
            return '0 ' . $units[0];
        }
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

// Helper function to calculate total traffic from Proxmox RRD data for the current month
// Takes raw RRD data array from Proxmox API (/nodes/{node}/{type}/{vmid}/rrddata?timeframe=month)
// and attempts to calculate total bytes in/out for the current calendar month.
// Assumes 'netin' and 'netout' in RRD data are cumulative byte counters.
// Handles cases where data might be missing or counter resets occur (with caveats).
if (!function_exists('pvewhmcs_calculate_monthly_traffic_from_rrd')) {
    function pvewhmcs_calculate_monthly_traffic_from_rrd($rrd_data_array, $data_key_in = 'netin', $data_key_out = 'netout') {
        $total_in = 0;
        $total_out = 0;
        // Ensure current time is in UTC for proper month calculation consistent with potential server settings
        // This defines the window for which we are calculating traffic.
        $current_time_utc = new DateTime("now", new DateTimeZone("UTC"));
        $current_month_start_timestamp = (new DateTime($current_time_utc->format("Y-m-01 00:00:00"), new DateTimeZone("UTC")))->getTimestamp();
        $next_month_start_timestamp = (new DateTime($current_time_utc->format("Y-m-01 00:00:00"), new DateTimeZone("UTC")))->modify('+1 month')->getTimestamp();

        // Validate input RRD data
        if (empty($rrd_data_array) || !is_array($rrd_data_array)) {
            return ['in' => 0, 'out' => 0, 'error' => 'No RRD data provided or invalid format'];
        }

        $first_in_of_month = null;
        $last_in_of_month = null;
        $first_out_of_month = null;
        $last_out_of_month = null;

        $found_first_entry_for_month = false;
        $last_valid_timestamp_in_month = 0; // Tracks the timestamp of the last considered data point

        // Iterate over each data point from RRD
        foreach ($rrd_data_array as $data_point) {
            $timestamp = isset($data_point['time']) ? intval($data_point['time']) : 0;

            // Filter data points to include only those within the current calendar month
            if ($timestamp < $current_month_start_timestamp || $timestamp >= $next_month_start_timestamp) {
                continue; // Skip data outside the current month
            }

            // Ensure the required data keys ('netin', 'netout') exist for the data point
            if (!isset($data_point[$data_key_in]) || !isset($data_point[$data_key_out])) {
                if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
                    logModuleCall('pvewhmcs_rrd_calc', "Missing '{$data_key_in}' or '{$data_key_out}' key for data point at timestamp {$timestamp}", $data_point, '');
                }
                continue;
            }

            $current_netin = floatval($data_point[$data_key_in]);
            $current_netout = floatval($data_point[$data_key_out]);

            // Capture the first relevant data point's values for the month
            if (!$found_first_entry_for_month) {
                $first_in_of_month = $current_netin;
                $first_out_of_month = $current_netout;
                $found_first_entry_for_month = true;
            }

            // Update with the latest data point encountered so far within the month
            // This ensures $last_in_of_month and $last_out_of_month hold the values from the final data point of the month.
            if($timestamp >= $last_valid_timestamp_in_month){
                 $last_in_of_month = $current_netin;
                 $last_out_of_month = $current_netout;
                 $last_valid_timestamp_in_month = $timestamp;
            }
        }

        // Proceed with calculation if we found at least one data point and have last values
        if ($found_first_entry_for_month && $last_in_of_month !== null && $last_out_of_month !== null) {
            // Assumption: RRD 'netin'/'netout' are cumulative byte counters.
            // Traffic for the month = (value at end of month) - (value at start of month).
            $total_in = $last_in_of_month - $first_in_of_month;
            $total_out = $last_out_of_month - $first_out_of_month;

            $error_message_in = null;
            $error_message_out = null;

            // Handle counter resets (where end value < start value for the period)
            // This is a simplified handling. True accuracy with resets is complex without knowing max counter value or having interval deltas.
            if ($total_in < 0) {
                $error_message_in = "Counter reset detected for IN traffic. Sum may be inaccurate. Last value in month: " . pvewhmcs_format_bytes($last_in_of_month);
                $total_in = $last_in_of_month; // Fallback: traffic is at least the value since the last reset in this period.
            }
            if ($total_out < 0) {
                $error_message_out = "Counter reset detected for OUT traffic. Sum may be inaccurate. Last value in month: " . pvewhmcs_format_bytes($last_out_of_month);
                $total_out = $last_out_of_month; // Fallback
            }

            // Consolidate error messages if any
            $final_error_message = null;
            if($error_message_in && $error_message_out) $final_error_message = "IN: {$error_message_in} | OUT: {$error_message_out}";
            else if ($error_message_in) $final_error_message = $error_message_in;
            else if ($error_message_out) $final_error_message = $error_message_out;

            return ['in' => $total_in, 'out' => $total_out, 'error' => $final_error_message];

        } elseif ($found_first_entry_for_month && ($last_in_of_month === null || $last_out_of_month === null)) {
            // This state indicates an issue, e.g., first entry found but no subsequent valid 'last' entry was set.
            return ['in' => 0, 'out' => 0, 'error' => 'Incomplete RRD data points for month after finding first entry.'];
        }

        // Default return if no suitable RRD data was found for the current month.
        return ['in' => 0, 'out' => 0, 'error' => 'No RRD data found for current month range or first/last entries missing.'];
    }
}


// ADMIN ADDON: VM Action - Start
function pvewhmcs_admin_vm_start($params) {
	$vmid = $params['vmid'];
	$node = $params['node'];
	$vtype = $params['vtype'];
	$serviceid = $vmid; // In this context, vmid from the list is the serviceid

	if (empty($vmid) || empty($node) || empty($vtype)) {
		return "<div class='alert alert-danger'>Error: Missing VMID, Node, or Type for Start action.</div>";
	}

	try {
		$hosting = Capsule::table('tblhosting')->where('id', $serviceid)->first();
		if (!$hosting) {
			return "<div class='alert alert-danger'>Error: Service ID {$serviceid} not found.</div>";
		}
		$server = Capsule::table('tblservers')->where('id', $hosting->server)->first();
		if (!$server) {
			return "<div class='alert alert-danger'>Error: Server for Service ID {$serviceid} not found.</div>";
		}

		$serverpassword_decrypted = localAPI('DecryptPassword', ['password2' => $server->password]);
		$serverpassword = $serverpassword_decrypted['password'];

		$proxmox = new PVE2_API($server->ipaddress, $server->username, "pam", $serverpassword);

		if ($proxmox->login()) {
			$api_response = $proxmox->post("/nodes/{$node}/{$vtype}/{$vmid}/status/start", []);
			if (isset($api_response['data']) || (is_string($api_response) && strpos($api_response, 'UPID:') === 0) ) {
				$_SESSION['pvewhmcs_admin_action_msg'] = "<div class='alert alert-success'>VM Start command issued for VMID {$vmid}. UPID: {$api_response['data']}</div>";
			} else {
				$error_detail = is_array($api_response) ? json_encode($api_response) : $api_response;
				$_SESSION['pvewhmcs_admin_action_msg'] = "<div class='alert alert-danger'>Error starting VM {$vmid}: {$error_detail}</div>";
			}
		} else {
			$_SESSION['pvewhmcs_admin_action_msg'] = "<div class='alert alert-danger'>Error: Could not log in to Proxmox server {$server->ipaddress}.</div>";
		}
	} catch (Exception $e) {
		$_SESSION['pvewhmcs_admin_action_msg'] = "<div class='alert alert-danger'>Exception during VM Start for VMID {$vmid}: " . $e->getMessage() . "</div>";
		if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
			logModuleCall('pvewhmcs_admin_vm_start', "Exception for VMID {$vmid}", $e->getMessage(), $e->getTraceAsString());
		}
	}
	// Redirect back to the VM list
	header("Location: " . pvewhmcs_BASEURL . "&tab=vms");
	exit;
}

// ADMIN ADDON: VM Action - Stop
function pvewhmcs_admin_vm_stop($params) {
	$vmid = $params['vmid'];
	$node = $params['node'];
	$vtype = $params['vtype'];
	$serviceid = $vmid;

	if (empty($vmid) || empty($node) || empty($vtype)) {
		return "<div class='alert alert-danger'>Error: Missing VMID, Node, or Type for Stop action.</div>";
	}

	try {
		$hosting = Capsule::table('tblhosting')->where('id', $serviceid)->first();
		$server = Capsule::table('tblservers')->where('id', $hosting->server)->first();
		$serverpassword_decrypted = localAPI('DecryptPassword', ['password2' => $server->password]);
		$serverpassword = $serverpassword_decrypted['password'];
		$proxmox = new PVE2_API($server->ipaddress, $server->username, "pam", $serverpassword);

		if ($proxmox->login()) {
			$api_response = $proxmox->post("/nodes/{$node}/{$vtype}/{$vmid}/status/stop", []);
			if (isset($api_response['data']) || (is_string($api_response) && strpos($api_response, 'UPID:') === 0)) {
				$_SESSION['pvewhmcs_admin_action_msg'] = "<div class='alert alert-success'>VM Stop command issued for VMID {$vmid}. UPID: {$api_response['data']}</div>";
			} else {
				$error_detail = is_array($api_response) ? json_encode($api_response) : $api_response;
				$_SESSION['pvewhmcs_admin_action_msg'] = "<div class='alert alert-danger'>Error stopping VM {$vmid}: {$error_detail}</div>";
			}
		} else {
			$_SESSION['pvewhmcs_admin_action_msg'] = "<div class='alert alert-danger'>Error: Could not log in to Proxmox server {$server->ipaddress}.</div>";
		}
	} catch (Exception $e) {
		$_SESSION['pvewhmcs_admin_action_msg'] = "<div class='alert alert-danger'>Exception during VM Stop for VMID {$vmid}: " . $e->getMessage() . "</div>";
	}
	header("Location: " . pvewhmcs_BASEURL . "&tab=vms");
	exit;
}

// ADMIN ADDON: VM Action - Shutdown
function pvewhmcs_admin_vm_shutdown($params) {
	$vmid = $params['vmid'];
	$node = $params['node'];
	$vtype = $params['vtype'];
	$serviceid = $vmid;

	if (empty($vmid) || empty($node) || empty($vtype)) {
		return "<div class='alert alert-danger'>Error: Missing VMID, Node, or Type for Shutdown action.</div>";
	}
	
	try {
		$hosting = Capsule::table('tblhosting')->where('id', $serviceid)->first();
		$server = Capsule::table('tblservers')->where('id', $hosting->server)->first();
		$serverpassword_decrypted = localAPI('DecryptPassword', ['password2' => $server->password]);
		$serverpassword = $serverpassword_decrypted['password'];
		$proxmox = new PVE2_API($server->ipaddress, $server->username, "pam", $serverpassword);

		if ($proxmox->login()) {
			$api_response = $proxmox->post("/nodes/{$node}/{$vtype}/{$vmid}/status/shutdown", []);
			if (isset($api_response['data']) || (is_string($api_response) && strpos($api_response, 'UPID:') === 0)) {
				$_SESSION['pvewhmcs_admin_action_msg'] = "<div class='alert alert-success'>VM Shutdown command issued for VMID {$vmid}. UPID: {$api_response['data']}</div>";
			} else {
				$error_detail = is_array($api_response) ? json_encode($api_response) : $api_response;
				$_SESSION['pvewhmcs_admin_action_msg'] = "<div class='alert alert-danger'>Error shutting down VM {$vmid}: {$error_detail}</div>";
			}
		} else {
			$_SESSION['pvewhmcs_admin_action_msg'] = "<div class='alert alert-danger'>Error: Could not log in to Proxmox server {$server->ipaddress}.</div>";
		}
	} catch (Exception $e) {
		$_SESSION['pvewhmcs_admin_action_msg'] = "<div class='alert alert-danger'>Exception during VM Shutdown for VMID {$vmid}: " . $e->getMessage() . "</div>";
	}
	header("Location: " . pvewhmcs_BASEURL . "&tab=vms");
	exit;
}

// ADMIN ADDON: VM Action - Reboot
function pvewhmcs_admin_vm_reboot($params) {
	$vmid = $params['vmid'];
	$node = $params['node'];
	$vtype = $params['vtype'];
	$serviceid = $vmid;

	if (empty($vmid) || empty($node) || empty($vtype)) {
		return "<div class='alert alert-danger'>Error: Missing VMID, Node, or Type for Reboot action.</div>";
	}

	try {
		$hosting = Capsule::table('tblhosting')->where('id', $serviceid)->first();
		$server = Capsule::table('tblservers')->where('id', $hosting->server)->first();
		$serverpassword_decrypted = localAPI('DecryptPassword', ['password2' => $server->password]);
		$serverpassword = $serverpassword_decrypted['password'];
		$proxmox = new PVE2_API($server->ipaddress, $server->username, "pam", $serverpassword);

		if ($proxmox->login()) {
			// Proxmox API for reboot might require the VM to be running.
			// A simple approach is to just send reboot. A more robust one would check status first.
			$api_response = $proxmox->post("/nodes/{$node}/{$vtype}/{$vmid}/status/reboot", []);
			if (isset($api_response['data']) || (is_string($api_response) && strpos($api_response, 'UPID:') === 0)) {
				$_SESSION['pvewhmcs_admin_action_msg'] = "<div class='alert alert-success'>VM Reboot command issued for VMID {$vmid}. UPID: {$api_response['data']}</div>";
			} else {
				$error_detail = is_array($api_response) ? json_encode($api_response) : $api_response;
				$_SESSION['pvewhmcs_admin_action_msg'] = "<div class='alert alert-danger'>Error rebooting VM {$vmid}: {$error_detail}</div>";
			}
		} else {
			$_SESSION['pvewhmcs_admin_action_msg'] = "<div class='alert alert-danger'>Error: Could not log in to Proxmox server {$server->ipaddress}.</div>";
		}
	} catch (Exception $e) {
		$_SESSION['pvewhmcs_admin_action_msg'] = "<div class='alert alert-danger'>Exception during VM Reboot for VMID {$vmid}: " . $e->getMessage() . "</div>";
	}
	header("Location: " . pvewhmcs_BASEURL . "&tab=vms");
	exit;
}

// Utility function (copied from servers/pvewhmcs/pvewhmcs.php for admin UI use)
// Ensure this function is defined only once in this file. 
// If it's already present elsewhere in this specific file, this would be a re-declaration.
if (!function_exists('time2format')) {
    function time2format($s) {
        $d = intval( $s / 86400 );
        $str = ''; 
        if ($d > 0) { // Only show days if there are any
            $str = $d . ' day' . ($d > 1 ? 's' : '') . ' ';
        }
    
        $s -= $d * 86400;
        $h = intval( $s / 3600 );
        $s -= $h * 3600;
        $m = intval( $s / 60 );
        $s -= $m * 60;
    
        // Always show H:M:S format, even if days are present or some parts are zero
        $str .= sprintf('%02d:%02d:%02d', $h, $m, $s);
        
        if (trim($str) == '00:00:00' && $d == 0) return '0s'; // Special case for exactly 0 uptime
        return trim($str);
    }
}


// ADMIN ADDON: View VM Details
function pvewhmcs_view_vm_detail_admin($params) {
	$vmid = $params['vmid'];
	$node = $params['node']; // The node where the VM is believed to be
	$vtype = $params['vtype'];
	$serviceid = $vmid;

	if (empty($vmid) || empty($node) || empty($vtype)) {
		echo "<div class='alert alert-danger'>Error: Missing VMID, Node, or Type for Detail View.</div>";
		return;
	}

	echo "<h3>Virtual Machine Details: VMID {$vmid}</h3>";

	try {
		$hosting_info = Capsule::table('tblhosting as h')
			->join('tblproducts as p', 'h.packageid', '=', 'p.id')
			->join('tblclients as c', 'h.userid', '=', 'c.id')
			->join('tblservers as s', 'h.server', '=', 's.id')
			->where('h.id', $serviceid)
			->where('s.type', '=', 'pvewhmcs')
			->select(
				'h.domainstatus as whmcs_service_status',
				'h.regdate as registration_date',
				'p.name as product_name',
				'c.firstname', 'c.lastname', 'c.companyname', 'c.email', 'h.userid',
				's.ipaddress as server_ip',
				's.username as server_username',
				's.password as server_password_encrypted',
				's.name as server_name'
			)
			->first();

		if (!$hosting_info) {
			echo "<div class='alert alert-danger'>Error: Could not retrieve hosting or server information for Service ID {$serviceid}.</div>";
			return;
		}
		
		$vm_db_info = Capsule::table('mod_pvewhmcs_vms')->where('id', $serviceid)->first();
		if (!$vm_db_info) {
			echo "<div class='alert alert-danger'>Error: VM details not found in module database for Service ID {$serviceid}.</div>";
			return;
		}


		$serverpassword_decrypted = localAPI('DecryptPassword', ['password2' => $hosting_info->server_password_encrypted]);
		$serverpassword = $serverpassword_decrypted['password'];

		$proxmox = new PVE2_API($hosting_info->server_ip, $hosting_info->server_username, "pam", $serverpassword);

		$vm_config = null;
		$vm_status_pve = null;
		$vm_rrd_stats = [];
		$pve_login_error = false;

		if ($proxmox->login()) {
			// Confirm VM exists on this node, or try to find it if node parameter was tentative
			$current_vm_info_from_cluster = null;
			$actual_node = $node; // Assume provided node is correct initially

			$cluster_resources = $proxmox->get("/cluster/resources?type=vm");
			foreach ($cluster_resources as $resource) {
				if ($resource['vmid'] == $vmid && $resource['type'] == $vtype) {
					$current_vm_info_from_cluster = $resource;
					$actual_node = $resource['node']; // Update to actual node
					break;
				}
			}

			if (!$current_vm_info_from_cluster) {
				echo "<div class='alert alert-warning'>VMID {$vmid} not found in Proxmox cluster resources. It might have been removed or is on a different node not accessible with current server config.</div>";
			} else {
				 $node = $actual_node; // Use the confirmed node from here
			}

			if($current_vm_info_from_cluster){
				$vm_config = $proxmox->get("/nodes/{$node}/{$vtype}/{$vmid}/config");
				$status_current = $proxmox->get("/nodes/{$node}/{$vtype}/{$vmid}/status/current");
				
				$vm_status_pve = $current_vm_info_from_cluster; // Use already fetched data
				$vm_status_pve['uptime_formatted'] = isset($status_current['uptime']) ? time2format($status_current['uptime']) : 'N/A';
				$vm_status_pve['cpu_percent'] = isset($status_current['cpu']) ? round($status_current['cpu'] * 100, 2) : 0;
				$vm_status_pve['mem_percent'] = ($status_current['maxmem'] > 0) ? intval($status_current['mem'] * 100 / $status_current['maxmem']) : 0;
				$vm_status_pve['disk_percent'] = ($status_current['maxdisk'] > 0) ? intval($status_current['disk'] * 100 / $status_current['maxdisk']) : 0;
				
				if ($vtype == 'lxc') {
					$vm_status_pve['swap_percent'] = ($status_current['maxswap'] > 0) ? intval($status_current['swap'] * 100 / $status_current['maxswap']) : 0;
				} else {
					$vm_status_pve['swap_percent'] = 0; // KVM swap is OS internal
				}


				// Fetch RRD data (simplified, adapt from pvewhmcs_ClientArea)
				$timeframes = ['day', 'week', 'month', 'year'];
				$metrics = [
					'cpu' => 'cpu&cf=AVERAGE',
					'mem' => 'maxmem&cf=AVERAGE', // PVE uses maxmem for memory graphs
					'net' => 'netin,netout&cf=AVERAGE',
					'diskio' => 'diskread,diskwrite&cf=AVERAGE'
				];




				if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
					$debug_rrd_url = "/nodes/{$node}/{$vtype}/{$vmid}/rrd?timeframe={$tf}&ds={$metric_params}"; // Example for one call
					logModuleCall('pvewhmcs_admin_rrd_debug', "Attempting RRD GET for URL (example)", $debug_rrd_url, '');
				}
				foreach ($metrics as $metric_key => $metric_params) {
						foreach ($timeframes as $tf) { // $tf will be 'day', 'week', 'month', 'year'
						try {
							$rrd_data = $proxmox->get("/nodes/{$node}/{$vtype}/{$vmid}/rrd?timeframe={$tf}&ds={$metric_params}");
							//$vm_rrd_stats[$metric_key][$tf] = isset($rrd_data['image']) ? base64_encode($rrd_data['image']) : '';
							$vm_rrd_stats[$metric_key][$tf] = isset($rrd_data['image']) ? base64_encode(utf8_decode($rrd_data['image'])) : '';
						} catch (Exception $e_rrd) {
							$vm_rrd_stats[$metric_key][$tf] = ''; // Set to empty if RRD fetch fails
							if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
								logModuleCall('pvewhmcs_admin_vm_detail', "RRD Error for VMID {$vmid} Metric {$metric_key} TF {$tf}", $e_rrd->getMessage(), '');
							}
						}
					}
				}
			}

		} else {
			$pve_login_error = true;
			echo "<div class='alert alert-danger'>Error: Could not log in to Proxmox server {$hosting_info->server_ip} to fetch details.</div>";
		}

		// Prepare variables for a template (or direct output)
		// This part would ideally use a Smarty template like clientarea.tpl
		// For now, let's output some basic info and structure for the template later

		$client_display_name = trim($hosting_info->firstname . ' ' . $hosting_info->lastname);
		if (!empty($hosting_info->companyname)) $client_display_name .= " ({$hosting_info->companyname})";

		// Basic Info Table
		echo "<h4>Service & Client Information</h4>";
		echo "<table class='table table-bordered' width='50%'>";
		echo "<tr><td width='30%'>WHMCS Service ID:</td><td>{$serviceid}</td></tr>";
		echo "<tr><td>Product Name:</td><td>{$hosting_info->product_name}</td></tr>";
		echo "<tr><td>Client:</td><td><a href='clientssummary.php?userid={$hosting_info->userid}'>{$client_display_name}</a> ({$hosting_info->email})</td></tr>";
		echo "<tr><td>Registration Date:</td><td>{$hosting_info->registration_date}</td></tr>";
		echo "<tr><td>WHMCS Status:</td><td>{$hosting_info->whmcs_service_status}</td></tr>";
		echo "<tr><td>Proxmox Server:</td><td>{$hosting_info->server_name} ({$hosting_info->server_ip})</td></tr>";
		echo "<tr><td>Assigned IPv4:</td><td>{$vm_db_info->ipaddress}</td></tr>";
		echo "</table>";
		
		if ($pve_login_error) return;
		if (!$current_vm_info_from_cluster) return; // Stop if VM not found on PVE

		// PVE Status & Config (mimicking clientarea.tpl structure)
		echo "<h4>Proxmox VE Status & Configuration</h4>";
		
		// Status dials (simplified representation here)
		echo '<div class="row" style="margin-bottom: 20px;">';
		if ($vm_status_pve) {
			echo '<div class="col-md-3"><strong>Status:</strong> ' . ucfirst($vm_status_pve['status']) . '<br><strong>Uptime:</strong> ' . $vm_status_pve['uptime_formatted'] . '</div>';
			echo '<div class="col-md-2"><strong>CPU:</strong> ' . $vm_status_pve['cpu_percent'] . '%</div>';
			echo '<div class="col-md-2"><strong>Memory:</strong> ' . $vm_status_pve['mem_percent'] . '%</div>';
			echo '<div class="col-md-2"><strong>Disk:</strong> ' . $vm_status_pve['disk_percent'] . '%</div>';
			if ($vtype == 'lxc') {
				echo '<div class="col-md-2"><strong>Swap:</strong> ' . $vm_status_pve['swap_percent'] . '%</div>';
			}
		} else {
			echo '<div class="col-md-12"><span style="color:red;">Could not fetch current PVE status.</span></div>';
		}
		echo '</div>';

		// Config Table
		if ($vm_config) {
			echo '<table class="table table-bordered table-striped">';
			echo "<tr><td><strong>VM Type:</strong></td><td>" . strtoupper($vtype) . "</td></tr>";
			echo "<tr><td><strong>OS Type (from PVE):</strong></td><td>{$vm_config['ostype']}</td></tr>";
			echo "<tr><td><strong>Name (from PVE):</strong></td><td>{$vm_config['name']}</td></tr>";
			echo "<tr><td><strong>CPUs:</strong></td><td>Sockets: {$vm_config['sockets']}, Cores: {$vm_config['cores']} ({$vm_config['cpu']})</td></tr>";
			echo "<tr><td><strong>Memory:</strong></td><td>{$vm_config['memory']} MB</td></tr>";
			if (isset($vm_config['swap']) && $vtype == 'lxc') echo "<tr><td><strong>Swap (LXC):</strong></td><td>{$vm_config['swap']} MB</td></tr>";
			
			// Network interfaces
			for ($i=0; $i<5; $i++) { // Check up to net4
				if (isset($vm_config["net{$i}"])) {
					echo "<tr><td><strong>NIC (net{$i}):</strong></td><td>" . str_replace(',', '<br/>', $vm_config["net{$i}"]) . "</td></tr>";
				}
			}
			// Disk interfaces
			$disk_keys = preg_grep('/^(ide|sata|scsi|virtio)\d+$/', array_keys($vm_config));
			foreach($disk_keys as $disk_key){
				if(strpos($vm_config[$disk_key], 'media=cdrom') === false){ // Don't show CDROMs as main disks
					 echo "<tr><td><strong>Disk ({$disk_key}):</strong></td><td>" . str_replace(',', '<br/>', $vm_config[$disk_key]) . "</td></tr>";
				}
			}
			if (isset($vm_config['rootfs']) && $vtype == 'lxc')  echo "<tr><td><strong>Root FS (LXC):</strong></td><td>" . str_replace(',', '<br/>', $vm_config['rootfs']) . "</td></tr>";

			echo "<tr><td><strong>Boot Order (PVE):</strong></td><td>{$vm_config['boot']}</td></tr>";
			echo "</table>";
		} else {
			echo "<p>Could not load VM configuration from Proxmox.</p>";
		}

		// Statistics Graphs (mimicking clientarea.tpl)
		echo "<h4>VM Statistics Graphs</h4>";
		$timeframes = ['day', 'week', 'month', 'year'];
		if (!empty($vm_rrd_stats)) {
			// Use unique IDs/classes to avoid conflict with other nav-tabs on the page
			echo '<ul class="nav nav-tabs admin-vm-rrd-tabs" role="tablist" id="adminVmRrdTabList">';
			foreach ($timeframes as $index => $tf_name) {
				$active_class = ($index == 0) ? "active" : "";
				echo "<li class='{$active_class}'><a data-toggle='tab' role='tab' href='#adminVm".ucfirst($tf_name)."Stat'>{$tf_name}</a></li>";
			}
			echo '</ul>';
			echo '<div class="tab-content admin-vm-rrd-tabs-content">';
			foreach ($timeframes as $index => $tf_name) {
				$active_class = ($index == 0) ? "active" : "";
				echo "<div id='adminVm".ucfirst($tf_name)."Stat' class='tab-pane {$active_class}'>";
				if (!empty($vm_rrd_stats['cpu'][$tf_name])) echo "<img src='data:image/png;base64,{$vm_rrd_stats['cpu'][$tf_name]}'/> ";
				if (!empty($vm_rrd_stats['mem'][$tf_name])) echo "<img src='data:image/png;base64,{$vm_rrd_stats['mem'][$tf_name]}'/> ";
				if (!empty($vm_rrd_stats['net'][$tf_name])) echo "<img src='data:image/png;base64,{$vm_rrd_stats['net'][$tf_name]}'/> ";
				if (!empty($vm_rrd_stats['diskio'][$tf_name])) echo "<img src='data:image/png;base64,{$vm_rrd_stats['diskio'][$tf_name]}'/>";
				if (empty($vm_rrd_stats['cpu'][$tf_name]) && empty($vm_rrd_stats['mem'][$tf_name])) echo "<p>No graph data available for this period.</p>";
				echo "</div>";
			}
			echo '</div>'; // tab-content

			// Add a script to ensure only this tab group is affected by tab clicks
			echo '
<script>
jQuery(function($){
	$("#adminVmRrdTabList a[data-toggle=\'tab\']").on("click", function(e){
		e.preventDefault();
		var $this = $(this);
		var target = $this.attr("href");
		$("#adminVmRrdTabList li").removeClass("active");
		$this.parent().addClass("active");
		$(".admin-vm-rrd-tabs-content .tab-pane").removeClass("active");
		$(target).addClass("active");
	});
});
</script>';

		} else {
			echo "<p>No statistics graph data available or Proxmox login failed.</p>";
		}

	} catch (Exception $e) {
		echo "<div class='alert alert-danger'>An error occurred while fetching VM details: " . $e->getMessage() . "</div>";
		if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
			logModuleCall('pvewhmcs_admin_vm_detail', "General Error for VMID {$vmid}", $e->getMessage(), $e->getTraceAsString());
		}
	}
	echo "<p><a href='".pvewhmcs_BASEURL."&tab=vms' class='btn btn-default'>&laquo; Back to VM List</a></p>";
}

