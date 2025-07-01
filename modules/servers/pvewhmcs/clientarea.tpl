<div class="row">
    {if $client_area_error_message}
        <div class="col-md-12">
            <div class="alert alert-danger text-center">
                <h4><i class="fas fa-exclamation-triangle"></i> Service Information Unvailable</h4>
                <p>{$client_area_error_message}</p>
                <p>Please contact support if the issue persists.</p>
            </div>
        </div>
    {else}
	<div style="text-align : left;">
	</div>
	<div class="col col-md-12">
		<div class="row">
			<div class="col col-md-3">
				<div class="row">
					<div class="col col-md-12">
                        {if $vm_config.vtype}
						    <img src="/modules/servers/pvewhmcs/img/{$vm_config.vtype}.png" alt="{$vm_config.vtype}"/>
                        {else}
                            <img src="/modules/servers/pvewhmcs/img/unknown.png" alt="Unknown VM Type"/>
                        {/if}
					</div>
				</div>			
				<div class="row">
					<div class="col col-md-12">
                        {if $vm_config.ostype}
						    <img src="/modules/servers/pvewhmcs/img/os/{$vm_config.ostype}.png" alt="{$vm_config.ostype}"/>
                        {else}
                             <img src="/modules/servers/pvewhmcs/img/os/unknown.png" alt="Unknown OS"/>
                        {/if}
					</div>
				</div>
			</div>
			<div class="col col-md-2">
                {if $vm_status.status}
				    <img src="/modules/servers/pvewhmcs/img/{$vm_status.status}.png" alt="{$vm_status.status}"/><br/>
				    <span style="text-transform: uppercase"><strong><i>{$vm_status.status}</i></strong></span><br/>
				    Up:&nbsp;{$vm_status.uptime|default:'N/A'}
                {else}
                    <img src="/modules/servers/pvewhmcs/img/unknown.png" alt="Unknown Status"/><br/>
                    <span style="text-transform: uppercase"><strong><i>Unknown</i></strong></span><br/>
                    Up:&nbsp;N/A
                {/if}
			</div>
			<div class="col col-md-7">
				<div class="row">
					<script src="/modules/servers/pvewhmcs/js/CircularLoader.js"></script>
					<div class="col col-md-3" style="height:106px;">
						<div id="c1" class="circle" data-percent="{$vm_status.cpu|default:0}"><strong>CPU</strong></div>
					</div>
					<div class="col col-md-3">
						<div id="c2" class="circle" data-percent="{$vm_status.memusepercent|default:0}"><strong>RAM</strong></div>
					</div>
					<div class="col col-md-3">
						<div id="c3" class="circle" data-percent="{$vm_status.diskusepercent|default:0}"><strong>Disk</strong></div>
					</div>
					<div class="col col-md-3">
						<div id="c4" class="circle" data-percent="{$vm_status.swapusepercent|default:0}"><strong>Swap</strong></div>
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
			<td><strong>IP</strong> (Addressing)</td><td><strong>{$vm_config.ipv4|default:'N/A'}</strong><br/>Subnet Mask:&nbsp;{$vm_config.netmask4|default:'N/A'}<br/>Gateway:&nbsp;{$vm_config.gateway4|default:'N/A'}</td>
		</tr>
		<tr>
			<td><strong>OS/etc</strong> (System)</td>
			<td>Kernel:&nbsp;{$vm_config.ostype|default:'N/A'}</td>
		</tr>
		<tr>
			<td><strong>Compute</strong> (CPU)</td>
			<td>{$vm_config.sockets|default:'N/A'}&nbsp;socket/s,&nbsp;{$vm_config.cores|default:'N/A'}&nbsp;core/s<br />
			Emulation: {$vm_config.cpu|default:'N/A'}</td>
		</tr>
		<tr>
			<td><strong>Memory</strong> (RAM)</td>
			<td>{$vm_config.memory|default:'N/A'}MB</td>
		</tr>
		<tr>
			<td><strong>NIC</strong> (Interface #1)</td>
			<td>{($vm_config.net0|replace:',':'<br/>')|default:'N/A'}</td>
		</tr>
		<tr>
			<td><strong>NIC</strong> (Interface #2)</td>
			<td>{$vm_config.net1|default:'N/A'}</td>
		</tr>
		<tr>
			<td><strong>Storage</strong> (SSD/HDD)</td>
			<td>
                {if $vm_config.rootfs}
                    {$rootfs=(","|explode:$vm_config.rootfs)}
                    {$rootfs[1]|default:''}
                {/if}
                {if $vm_config.ide0}
                    {$disk=(","|explode:$vm_config.ide0)}
                    {$disk[1]|default:''}
                {/if}
                {if $vm_config.scsi0}
                    {($vm_config.scsi0|replace:',':'<br/>')|default:''}
                {/if}
                {if $vm_config.virtio0}
                    {($vm_config.virtio0|replace:',':'<br/>')|default:''}
                {/if}
                {if !$vm_config.rootfs && !$vm_config.ide0 && !$vm_config.scsi0 && !$vm_config.virtio0}
                    N/A
                {/if}
			</td>
		</tr>
	</table>
    {/if} {* End of if not $client_area_error_message *}

	{if ($smarty.get.a eq 'vmStat' || $smarty.get.a eq 'loadIsoPage')}
	{* Determine active tab for general actions like stats or ISO loading *}
	<ul class="nav nav-tabs client-tabs" role="tab-list">
		<li {if ($smarty.get.a eq 'vmStat')}class="active"{/if}>
			<a href="clientarea.php?action=productdetails&amp;id={$params.serviceid}&amp;modop=custom&amp;a=vmStat">VM Statistics</a>
		</li>
		<li {if ($smarty.get.a eq 'loadIsoPage')}class="active"{/if}>
			<a href="clientarea.php?action=productdetails&amp;id={$params.serviceid}&amp;modop=custom&amp;a=loadIsoPage">Load ISO</a>
		</li>
		{* Add other future top-level action tabs here if needed *}
	</ul>
	{/if}

	{if ($smarty.get.a eq 'vmStat')}
	<h4>VM Statistics</h4>
	{* Specific sub-tabs for statistics *}
	<ul class="nav nav-tabs client-tabs" role="tab-list">
		<li class="active"><a id="dailytab" data-toggle="tab" role="tab" href="#dailystat">Daily</a></li>
		<li><a id="weeklystat_tab" data-toggle="tab" role="tab" href="#weeklystat">Weekly</a></li>
		<li><a id="monthlystat_tab" data-toggle="tab" role="tab" href="#monthlystat">Monthly</a></li>
		<li><a id="yearlystat_tab" data-toggle="tab" role="tab" href="#yearlystat">Yearly</a></li>
	</ul>
	<div class="tab-content admin-tabs">
		<div id="dailystat" class="tab-pane active">
			<img src="data:image/png;base64,{$vm_statistics['cpu']['day']}"/>
			<img src="data:image/png;base64,{$vm_statistics['maxmem']['day']}"/>
			<img src="data:image/png;base64,{$vm_statistics['netinout']['day']}"/>
			<img src="data:image/png;base64,{$vm_statistics['diskrw']['day']}"/>
		</div>
		<div id="weeklystat" class="tab-pane">
			<img src="data:image/png;base64,{$vm_statistics['cpu']['week']}"/>
			<img src="data:image/png;base64,{$vm_statistics['maxmem']['week']}"/>
			<img src="data:image/png;base64,{$vm_statistics['netinout']['week']}"/>
			<img src="data:image/png;base64,{$vm_statistics['diskrw']['week']}"/>
		</div>
		<div id="monthlystat" class="tab-pane">
			<img src="data:image/png;base64,{$vm_statistics['cpu']['month']}"/>
			<img src="data:image/png;base64,{$vm_statistics['maxmem']['month']}"/>
			<img src="data:image/png;base64,{$vm_statistics['netinout']['month']}"/>
			<img src="data:image/png;base64,{$vm_statistics['diskrw']['month']}"/>
		</div>
		<div id="yearlystat" class="tab-pane">
			<img src="data:image/png;base64,{$vm_statistics['cpu']['year']}"/>
			<img src="data:image/png;base64,{$vm_statistics['maxmem']['year']}"/>
			<img src="data:image/png;base64,{$vm_statistics['netinout']['year']}"/>
			<img src="data:image/png;base64,{$vm_statistics['diskrw']['year']}"/>
		</div>
	</div>
	{/if}

	
</div>
