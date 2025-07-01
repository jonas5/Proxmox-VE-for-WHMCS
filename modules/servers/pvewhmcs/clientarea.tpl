<div class="row">
	<div style="text-align : left;">
	</div>
	<div class="col col-md-12">
		<div class="row">
			<div class="col col-md-3">
				<div class="row">
					<div class="col col-md-12">
						<img src="/modules/servers/pvewhmcs/img/{$vm_config['vtype']}.png"/>
					</div>
				</div>			
				<div class="row">
					<div class="col col-md-12">
						<img src="/modules/servers/pvewhmcs/img/os/{$vm_config['ostype']}.png"/>
					</div>
				</div>
			</div>
			<div class="col col-md-2">
				<img src="/modules/servers/pvewhmcs/img/{$vm_status['status']}.png"/><br/>
				<span style="text-transform: uppercase"><strong><i>{$vm_status['status']}</i></strong></span><br/>
				Up:&nbsp;{$vm_status['uptime']}
				
			</div>
			<div class="col col-md-7">
				<div class="row">
					<script src="/modules/servers/pvewhmcs/js/CircularLoader.js"></script>
					<div class="col col-md-3" style="height:106px;">
						<div id="c1" class="circle" data-percent="{$vm_status['cpu']}"><strong>CPU</strong></div>
					</div>
					<div class="col col-md-3">
						<div id="c2" class="circle" data-percent="{$vm_status['memusepercent']}"><strong>RAM</strong></div>
					</div>
					<div class="col col-md-3">
						<div id="c3" class="circle" data-percent="{$vm_status['diskusepercent']}"><strong>Disk</strong></div>
					</div>
					<div class="col col-md-3">
						<div id="c4" class="circle" data-percent="{$vm_status['swapusepercent']}"><strong>Swap</strong></div>
					</div>
				</div>
				<script>
				$(document).ready(function() {
					$('.circle').each(function(){
						$(this).circularloader({
							progressPercent: $(this).attr("data-percent"),
							fontSize: "13px",
							radius: 30,
							progressBarWidth: 8,
							progressBarBackground: "#D6B1F9",
							progressBarColor: "#802DBC",
						});
					});
				});
				</script>
			</div>
		</div>
	</div>

	<table class="table table-bordered table-striped">
		<tr>
			<td><strong>IP</strong> (Addressing)</td><td><strong>{$vm_config['ipv4']}</strong><br/>Subnet Mask:&nbsp;{$vm_config['netmask4']}<br/>Gateway:&nbsp;{$vm_config['gateway4']}</td>
		</tr>
		<tr>
			<td><strong>OS/etc</strong> (System)</td>
			<td>Kernel:&nbsp;{$vm_config['ostype']}</td>
		</tr>
		<tr>
			<td><strong>Compute</strong> (CPU)</td>
			<td>{$vm_config['sockets']}&nbsp;socket/s,&nbsp;{$vm_config['cores']}&nbsp;core/s<br />
			Emulation: {$vm_config['cpu']}</td>
		</tr>
		<tr>
			<td><strong>Memory</strong> (RAM)</td>
			<td>{$vm_config['memory']}MB</td>
		</tr>
		<tr>
			<td><strong>NIC</strong> (Interface #1)</td>
			<td>{($vm_config['net0']|replace:',':'<br/>')}</td>
		</tr>
		<tr>
			<td><strong>NIC</strong> (Interface #2)</td>
			<td>{$vm_config['net1']}</td>
		</tr>
		<tr>
			<td><strong>Storage</strong> (SSD/HDD)</td>
			<td>
			{$rootfs=(","|explode:$vm_config['rootfs'])}
			{$disk=(","|explode:$vm_config['ide0'])}
			{$disk[1]}
			{$rootfs[1]}
			{($vm_config['scsi0']|replace:',':'<br/>')}
			{($vm_config['virtio0']|replace:',':'<br/>')}
			</td>
		</tr>
	</table>

    {if $smarty.get.kernelconfigmessage}
        <div class="alert alert-info">
            {$smarty.get.kernelconfigmessage}
        </div>
    {/if}
    {if $smarty.get.kernelconfigerror}
        <div class="alert alert-danger">
            {$smarty.get.kernelconfigerror}
        </div>
    {/if}

    {* --- Conditional Display Logic Starts Here --- *}
    {if $smarty.get.modaction eq 'vmstats'}
        {* Display VM Statistics *}
        {if ($vm_statistics)} {* Assuming vm_statistics might not be populated if not originally called by vmStat action *}
            <h4>VM Statistics</h4>
            {* Removed tab navigation for stats, direct display *}
            <div class="vm-statistics-graphs">
                <h5>Daily</h5>
                <img src="data:image/png;base64,{$vm_statistics['cpu']['day']}"/>
                <img src="data:image/png;base64,{$vm_statistics['maxmem']['day']}"/>
                <img src="data:image/png;base64,{$vm_statistics['netinout']['day']}"/>
                <img src="data:image/png;base64,{$vm_statistics['diskrw']['day']}"/>

                <h5>Weekly</h5>
                <img src="data:image/png;base64,{$vm_statistics['cpu']['week']}"/>
                <img src="data:image/png;base64,{$vm_statistics['maxmem']['week']}"/>
                <img src="data:image/png;base64,{$vm_statistics['netinout']['week']}"/>
                <img src="data:image/png;base64,{$vm_statistics['diskrw']['week']}"/>

                <h5>Monthly</h5>
                <img src="data:image/png;base64,{$vm_statistics['cpu']['month']}"/>
                <img src="data:image/png;base64,{$vm_statistics['maxmem']['month']}"/>
                <img src="data:image/png;base64,{$vm_statistics['netinout']['month']}"/>
                <img src="data:image/png;base64,{$vm_statistics['diskrw']['month']}"/>

                <h5>Yearly</h5>
                <img src="data:image/png;base64,{$vm_statistics['cpu']['year']}"/>
                <img src="data:image/png;base64,{$vm_statistics['maxmem']['year']}"/>
                <img src="data:image/png;base64,{$vm_statistics['netinout']['year']}"/>
                <img src="data:image/png;base64,{$vm_statistics['diskrw']['year']}"/>
            </div>
        {else}
            <p>VM Statistics data is not available. This might be because the 'vmStat' action was not triggered to populate this data.</p>
        {/if}
        <p><a href="clientarea.php?action=productdetails&id={$params.serviceid}" class="btn btn-default">Back to VM Details</a></p>

    {elseif $smarty.get.modaction eq 'kernelconfig'}
        {* Display Kernel Configuration Form *}
        <h4>Select OS Type (Kernel/Loader)</h4>
        <form action="clientarea.php?action=productdetails&id={$params.serviceid}&modop=custom&a=saveKernelConfig" method="post">
            <input type="hidden" name="serviceid" value="{$params.serviceid}">
            <input type="hidden" name="token" value="{$csrf_token}">
            <div class="form-group">
                <label for="kernel_loader_os">Operating System Type:</label>
                <select name="kernel_loader_os" id="kernel_loader_os" class="form-control">
                    <option value="l26" {if $vm_config.ostype eq 'l26' or ($vm_config.ostype ne 'win10' and $vm_config.ostype ne 'win11' and $vm_config.ostype ne 'other')}selected{/if}>Linux (Kernel 2.6+ / SeaBIOS)</option>
                    <option value="win10" {if $vm_config.ostype eq 'win10'}selected{/if}>Windows 10/2016/2019 (OVMF/UEFI)</option>
                    <option value="win11" {if $vm_config.ostype eq 'win11'}selected{/if}>Windows 11/2022/2025 (OVMF/UEFI)</option>
                    <option value="other" {if $vm_config.ostype eq 'other'}selected{/if}>Other/Custom (SeaBIOS)</option>
                </select>
            </div>
            <p class="help-block"><strong>Important:</strong> Changing this setting will reconfigure your virtual machine. A <strong>reboot is required</strong> from the Proxmox VE control panel or via OS for changes to take effect.
            <br/>For Windows UEFI (OVMF), ensure your VM template/ISO is UEFI compatible. An EFI disk will be configured if one is not already present (requires available storage on the node).
            <br/>Selecting a Linux type typically uses SeaBIOS. Selecting a Windows type will configure OVMF for UEFI support.</p>
            <button type="submit" class="btn btn-primary">Save Configuration & Reboot VM</button>
            <a href="clientarea.php?action=productdetails&id={$params.serviceid}" class="btn btn-default">Cancel</a>
        </form>

    {else}
        {* Default view: No specific modaction, or an unrecognized one.
           The VM information table is already above this conditional block.
           So, nothing specific needs to be added here if the table is always shown
           UNLESS other content (like stats or kernel config) is requested.
           If the table itself should ALSO be conditional, it needs to be moved inside an 'else' or default part of this if/else structure.
           For now, assuming the table is always visible unless 'vmstats' or 'kernelconfig' is chosen.
        *}
        {* Placeholder if we want to add a title for the default table view,
           if it's not already implicitly part of the page structure above.
           <h4>VM Overview</h4>
           The table is already rendered above this if/else block.
        *}
    {/if}
    {* --- Conditional Display Logic Ends Here --- *}
	
</div>
