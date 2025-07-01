<div class="container custom-args-management">

  <h4 class="section-title">Advanced Boot Options (Custom QEMU Arguments)</h4>

  {if $error_message}
    <div class="alert alert-danger">{$error_message}</div>
  {/if}

  {if $action_message}
    <div class="alert {if $action_message|strstr:'Error:'}alert-danger{else}alert-success{/if}">
      {$action_message}
    </div>
  {/if}

  {if !$error_message}
    <form method="post" action="clientarea.php?action=productdetails&amp;id={$params.serviceid}&amp;modop=custom&amp;a=saveCustomArgsConfig">
      <input type="hidden" name="token" value="{$csrf_token}">

      <div class="panel panel-default">
        <div class="panel-heading">
          <h5 class="panel-title">QEMU Arguments</h5>
        </div>
        <div class="panel-body">
          <div class="alert alert-warning">
            <strong><i class="fas fa-exclamation-triangle"></i> Warning:</strong> Modifying these arguments directly can lead to an unbootable VM if not done correctly. These settings are intended for advanced users who understand QEMU command-line options. Incorrect syntax or unsupported options may be ignored by Proxmox or cause errors.
          </div>

          <div class="form-group">
            <label for="custom_qemu_args">Custom QEMU Arguments:</label>
            <textarea name="custom_qemu_args" id="custom_qemu_args" class="form-control" rows="8" placeholder="e.g., -device vfio-pci,host=01:00.0 -smbios type=1,manufacturer=MyCorp">{$current_custom_args|escape:'html'}</textarea>
            <small class="form-text text-muted">
              Enter arguments exactly as they would be passed to the QEMU command line (e.g., <code>-option value -another value</code>). Refer to Proxmox and QEMU documentation for valid options.
              Leave blank to remove custom arguments.
            </small>
          </div>
        </div>
      </div>

      <div class="form-actions text-right">
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-save"></i> Save Custom Arguments
        </button>
        <a href="clientarea.php?action=productdetails&amp;id={$params.serviceid}" class="btn btn-secondary">
          Cancel
        </a>
      </div>
    </form>
  {else}
    <p class="text-danger">Could not load advanced boot options interface due to the error mentioned above.</p>
  {/if}
</div>
