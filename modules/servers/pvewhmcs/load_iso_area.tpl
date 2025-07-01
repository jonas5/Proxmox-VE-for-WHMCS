<div class="container iso-management">

  <h4 class="section-title">Load ISO Image</h4>

  {if $error_message}
    <div class="alert alert-danger">{$error_message}</div>
  {/if}

  {if $action_message}
    <div class="alert {if $action_message|strstr:'Error:'}alert-danger{else}alert-success{/if}">
      {$action_message}
    </div>
  {/if}

  {if !$error_message}
    <div class="row">

      <!-- Current ISO Status -->
      <div class="col-md-6">
        <div class="panel panel-default iso-panel">
          <div class="panel-heading">
            <h5 class="panel-title">Current ISO Status</h5>
          </div>
          <div class="panel-body">
            {if $current_iso}
              <p><strong>Currently Mounted ISO:</strong> {$current_iso}</p>
              <p><strong>Mounted on Drive:</strong> {$current_drive}</p>
              <form method="post" action="clientarea.php?action=productdetails&amp;id={$params.serviceid}&amp;modop=custom&amp;a=unmountIso">
                <input type="hidden" name="drive_to_unmount" value="{$current_drive}" />
                <button type="submit" class="btn btn-danger btn-sm">
                  <i class="fas fa-eject"></i> Eject ISO
                </button>
              </form>
            {else}
              <p>No ISO image is currently mounted.</p>
            {/if}
          </div>
        </div>
      </div>

      <!-- Mount New ISO -->
      <div class="col-md-6">
        <div class="panel panel-default iso-panel">
          <div class="panel-heading">
            <h5 class="panel-title">Mount New ISO</h5>
          </div>
          <div class="panel-body">
            {if $iso_images|@count > 0}
              <form method="post" action="clientarea.php?action=productdetails&amp;id={$params.serviceid}&amp;modop=custom&amp;a=mountIso">
                <input type="hidden" name="customAction" value="true">
                <input type="hidden" name="storage_location" value="{$iso_storage_assumed|default:'local'}" />

                <div class="form-group">
                  <label for="iso_image">Select ISO Image:</label>
                  <select name="iso_image" id="iso_image" class="form-control">
                    {foreach from=$iso_images item=iso}
                      <option value="{$iso}">{$iso}</option>
                    {/foreach}
                  </select>
                  <small class="form-text text-muted">
                    ISOs listed from storage: <strong>{$iso_storage_assumed|default:'unknown'}</strong> on node <strong>{$first_node|default:'unknown'}</strong>
                  </small>
                </div>

                <div class="form-group">
                  <label for="drive_to_use">Mount to Drive:</label>
                  <select name="drive_to_use" id="drive_to_use" class="form-control">
                    {foreach from=['ide0','ide1','ide2','ide3','sata0','sata1','sata2','sata3','sata4','sata5'] item=drive}
                      <option value="{$drive}" {if $current_drive eq $drive or ($drive eq 'ide2' and !$current_drive)}selected{/if}>
                        {$drive|upper}{if $drive eq 'ide2'} (Recommended CD/DVD){/if}
                      </option>
                    {/foreach}
                  </select>
                  <small class="form-text text-muted">
                    Select the virtual CD/DVD drive. If an ISO is already on this drive, it will be replaced.
                  </small>
                </div>


                <div class="form-group">
                  <div class="checkbox">
                   <label>
                      <input type="checkbox" name="force_bios_on_reboot" value="1">
                      Force BIOS/UEFI setup screen on next reboot.
                    </label>
                  </div>
                </div>


                <div class="form-group">
                <div class="checkbox">
                      <label>
  	                    <input type="checkbox" name="force_boot_from_iso" value="1">
        	              Attempt to boot from this ISO on next reboot (temporarily sets CD/DVD as primary boot device).
                      </label>
                    </div>
                 </div>

                <div class="form-actions text-right">
                  <button type="submit" class="btn btn-primary">
                    <i class="fas fa-compact-disc"></i> Mount Selected ISO
                  </button>
                </div>
              </form>
            {else}
              <p class="text-muted">
                No ISO images found in storage '<strong>{$iso_storage_assumed|default:'unknown'}</strong>' on node '<strong>{$first_node|default:'unknown'}</strong>'.
                Please upload ISOs to your Proxmox server.
              </p>
            {/if}
          </div>
        </div>
      </div>

    </div>
  {else}
    <p class="text-danger">Could not load ISO management interface due to the error mentioned above.</p>
  {/if}

</div>
