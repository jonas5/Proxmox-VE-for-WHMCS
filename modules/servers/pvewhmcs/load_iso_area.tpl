<h4>Load ISO Image</h4>

{if $error_message}
    <div class="alert alert-danger">
        {$error_message}
    </div>
{/if}

{if $action_message}
    <div class="alert {if $action_message|strstr:'Error:'}alert-danger{else}alert-success{/if}">
        {$action_message}
    </div>
{/if}

{if !$error_message}
    <div class="row">
        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">Current ISO Status</h3>
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

        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">Mount New ISO</h3>
                </div>
                <div class="panel-body">
                    {if $iso_images|@count > 0}
                        <form method="post" action="clientarea.php?action=productdetails&amp;id={$params.serviceid}&amp;modop=custom&amp;a=mountIso">
                            <div class="form-group">
                                <label for="iso_image">Select ISO Image:</label>
                                <select name="iso_image" id="iso_image" class="form-control">
                                    {foreach from=$iso_images item=iso}
                                        <option value="{$iso}">{$iso}</option>
                                    {/foreach}
                                </select>
                                <span class="help-block">ISOs listed from storage: {$iso_storage_assumed|default:'unknown'} on node {$first_node|default:'unknown'}</span>
                            </div>

                            <div class="form-group">
                                <label for="drive_to_use">Mount to Drive:</label>
                                <select name="drive_to_use" id="drive_to_use" class="form-control">
                                    <option value="ide0" {if $current_drive eq "ide0"}selected{/if}>IDE0</option>
                                    <option value="ide1" {if $current_drive eq "ide1"}selected{/if}>IDE1</option>
                                    <option value="ide2" {if !$current_drive || $current_drive eq "ide2"}selected{/if}>IDE2 (Recommended CD/DVD)</option>
                                    <option value="ide3" {if $current_drive eq "ide3"}selected{/if}>IDE3</option>
                                    <option value="sata0" {if $current_drive eq "sata0"}selected{/if}>SATA0</option>
                                    <option value="sata1" {if $current_drive eq "sata1"}selected{/if}>SATA1</option>
                                    <option value="sata2" {if $current_drive eq "sata2"}selected{/if}>SATA2</option>
                                    <option value="sata3" {if $current_drive eq "sata3"}selected{/if}>SATA3</option>
                                    <option value="sata4" {if $current_drive eq "sata4"}selected{/if}>SATA4</option>
                                    <option value="sata5" {if $current_drive eq "sata5"}selected{/if}>SATA5</option>
                                </select>
                                <span class="help-block">Select the virtual CD/DVD drive. If an ISO is already on this drive, it will be replaced.</span>
                            </div>
                             <input type="hidden" name="storage_location" value="{$iso_storage_assumed|default:'local'}" />


                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-compact-disc"></i> Mount Selected ISO
                            </button>
                        </form>
                    {else}
                        <p>No ISO images found in storage '{$iso_storage_assumed|default:'unknown'}' on node '{$first_node|default:'unknown'}'. Please upload ISOs to your Proxmox server.</p>
                    {/if}
                </div>
            </div>
        </div>
    </div>
{else}
    <p>Could not load ISO management interface due to the error mentioned above.</p>
{/if}

{* Font Awesome icons are used, ensure WHMCS template includes it, or add here if necessary *}
{* Example: <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" /> *}
{* WHMCS six template usually includes it. *}
