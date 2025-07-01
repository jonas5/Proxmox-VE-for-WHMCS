<div class="container vm-boost-management">

  <h4 class="section-title">Boost Virtual Machine Resources</h4>

  {if $error_message}
    <div class="alert alert-danger">{$error_message}</div>
  {/if}

  {if $action_message}
    <div class="alert {if $action_message|strstr:'Error:'}alert-danger{else}alert-success{/if}">
      {$action_message}
    </div>
  {/if}

  {if !$error_message}
    <form method="post" action="clientarea.php?action=productdetails&amp;id={$params.serviceid}&amp;modop=custom&amp;a=applyBoostConfig">
      <input type="hidden" name="token" value="{$csrf_token}">

      <div class="panel panel-default">
        <div class="panel-heading">
          <h5 class="panel-title">Configure Temporary Boost</h5>
        </div>
        <div class="panel-body">
          <div class="alert alert-info">
            <p><i class="fas fa-info-circle"></i> Current Configuration:</p>
            <ul>
              <li>CPU Cores: {$current_cores}</li>
              <li>Memory: {$current_memory_mb} MB</li>
              <li>Maximum Configurable vCPUs for this VM: {$max_allowed_vcpus_for_vm} (Set in Proxmox VM options under 'cpus')</li>
            </ul>
          </div>

          <div class="form-group">
            <label for="boost_cpu_cores">Desired Total CPU Cores:</label>
            <input type="number" name="boost_cpu_cores" id="boost_cpu_cores" class="form-control" value="{$current_cores}" min="{$current_cores}" max="{$max_allowed_vcpus_for_vm|min:$max_boost_cpu_cores_ui}" required>
            <small class="form-text text-muted">Adjust total vCPU cores. Cannot exceed VM's maximum vCPU limit ({$max_allowed_vcpus_for_vm}) or system limit ({$max_boost_cpu_cores_ui}). Current: {$current_cores}.</small>
          </div>

          <div class="form-group">
            <label for="boost_memory_mb">Desired Total Memory (MB):</label>
            <input type="number" name="boost_memory_mb" id="boost_memory_mb" class="form-control" value="{$current_memory_mb}" min="{$current_memory_mb}" max="{$max_boost_memory_gb*1024}" step="128" required>
            <small class="form-text text-muted">Adjust total memory in MB. Max UI suggestion: {$max_boost_memory_gb} GB. Current: {$current_memory_mb} MB.</small>
          </div>

          <div class="form-group">
            <label for="boost_duration_hours">Boost Duration (Hours):</label>
            <select name="boost_duration_hours" id="boost_duration_hours" class="form-control">
              <option value="1">1 Hour</option>
              <option value="3">3 Hours</option>
              <option value="6">6 Hours</option>
              <option value="12">12 Hours</option>
              <option value="24">24 Hours</option>
            </select>
            <small class="form-text text-muted">Select how long you'd like this boost. (Note: Automatic reversion is not yet implemented in this version).</small>
          </div>

          <div class="alert alert-warning">
            <strong><i class="fas fa-exclamation-triangle"></i> Important Notes:</strong>
            <ul>
              <li>This feature attempts to <strong>hot-plug</strong> resources. Success depends on guest OS support (e.g., VirtIO drivers installed and loaded) and the VM's configuration in Proxmox (hotplug flags may need to be enabled: `hotplug: cpu,memory`).</li>
              <li>Some changes, especially if decreasing resources or if hotplug is not fully supported by the guest, may require a <strong>VM reboot</strong> to take full effect or might not apply live.</li>
              <li>The selected duration is for planning and future billing integration. <strong>Boosts are NOT automatically reverted in this version.</strong> You will need to manually revert changes or the boost will remain until the next VM reboot/reconfiguration for some parameters.</li>
              <li>Ensure the desired CPU cores do not exceed the 'Maximum Configurable vCPUs' set for your VM in Proxmox.</li>
            </ul>
          </div>

        </div>
      </div>

      <div class="form-actions text-right">
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-bolt"></i> Apply Boost
        </button>
        <a href="clientarea.php?action=productdetails&amp;id={$params.serviceid}" class="btn btn-secondary">
          Cancel
        </a>
      </div>
    </form>

    {* Example for simple range sliders using jQuery UI if available - more advanced sliders might need dedicated libraries *}
    {* This is a basic example; you might need to include jQuery UI or another slider library if not standard in your theme *}
    {if true}
    {* Enable this if jQuery UI is available and you want sliders instead of number inputs.
       Adjusting this to work perfectly as sliders requires more JS for value display etc.
       For now, number inputs are more robust without extra JS dependencies.
    <link rel="stylesheet" href="//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
    <script>
    $(function() {
        // Basic example, would need refinement
        // $("#boost_cpu_cores_slider").slider({
        //   value: {$current_cores}, min: {$current_cores}, max: {$max_allowed_vcpus_for_vm|min:$max_boost_cpu_cores_ui},
        //   slide: function(event, ui) { $("#boost_cpu_cores").val(ui.value); }
        // });
        // $("#boost_memory_mb_slider").slider({
        //   value: {$current_memory_mb}, min: {$current_memory_mb}, max: {$max_boost_memory_gb*1024}, step: 128,
        //   slide: function(event, ui) { $("#boost_memory_mb").val(ui.value); }
        // });
    });
    </script>
    *}
    {/if}

  {else}
    <p class="text-danger">Could not load VM Boost interface due to the error mentioned above.</p>
  {/if}
</div>
