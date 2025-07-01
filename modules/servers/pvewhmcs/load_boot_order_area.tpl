<div class="container boot-order-management">

  <h4 class="section-title">Configure Boot Order</h4>

  {if $error_message}
    <div class="alert alert-danger">{$error_message}</div>
  {/if}

  {if $action_message}
    <div class="alert {if $action_message|strstr:'Error:'}alert-danger{else}alert-success{/if}">
      {$action_message}
    </div>
  {/if}

  {if !$error_message}
    <form method="post" action="clientarea.php?action=productdetails&amp;id={$params.serviceid}&amp;modop=custom&amp;a=saveBootOrderConfig">
      <input type="hidden" name="token" value="{$csrf_token}">

      <div class="panel panel-default">
        <div class="panel-heading">
          <h5 class="panel-title">Boot Devices</h5>
        </div>
        <div class="panel-body">
          <p>
            Current raw boot order string: <code>{$current_boot_order_raw|default:'Not set or legacy (cdrom, disk, net)'}</code>
          </p>
          <p>
            Drag and drop to reorder. Only checked devices will be included in the boot order.
            The first checked device will be the primary boot device.
          </p>

          {assign var="current_order_devices" value=[]}
          {assign var="legacy_boot" value=false}
          {if $current_boot_order_raw && $current_boot_order_raw|strstr:'order='}
            {assign var="order_string_parts" value="="|explode:$current_boot_order_raw}
            {if $order_string_parts[1]}
              {assign var="current_order_devices" value=";"|explode:$order_string_parts[1]}
            {/if}
          {elseif $current_boot_order_raw}
            {* Handle legacy format like "cdn", "c", "d", "n" *}
            {assign var="legacy_boot" value=true}
            {if $current_boot_order_raw|strstr:'c'}
                {append var='current_order_devices' value='ide2' scope='local'}
            {/if}
            {if $current_boot_order_raw|strstr:'d'}
                {append var='current_order_devices' value='scsi0' scope='local'} {* Assuming scsi0 for disk, could be others *}
            {/if}
            {if $current_boot_order_raw|strstr:'n'}
                {append var='current_order_devices' value='net0' scope='local'}
            {/if}
          {/if}

          {* Ensure all available_devices are shown, checked if in current_order_devices *}
          {assign var="all_display_devices" value=[]}
          {foreach from=$available_devices item=dev}
            {if $dev} {* Ensure device name is not empty *}
              {assign var="is_enabled" value=false}
              {if $current_order_devices|is_array}
                {foreach from=$current_order_devices item=ordered_dev}
                  {if $ordered_dev == $dev}
                    {assign var="is_enabled" value=true}
                  {/if}
                {/foreach}
              {/if}
              {append var='all_display_devices' value=['name' => $dev, 'enabled' => $is_enabled] scope='local'}
            {/if}
          {/foreach}

          {* Add devices from current order that might not be in "available_devices" (e.g. if config parsing was basic) *}
          {if $current_order_devices|is_array}
            {foreach from=$current_order_devices item=ordered_dev}
              {if $ordered_dev}
                {assign var="found_in_display" value=false}
                {if $all_display_devices|is_array}
                  {foreach from=$all_display_devices item=disp_dev}
                    {if $disp_dev.name == $ordered_dev}
                      {assign var="found_in_display" value=true}
                    {/if}
                  {/foreach}
                {/if}
                {if !$found_in_display}
                  {append var='all_display_devices' value=['name' => $ordered_dev, 'enabled' => true] scope='local'}
                {/if}
              {/if}
            {/foreach}
          {/if}

          <ul id="boot-device-list" class="list-group">
            {foreach from=$all_display_devices item=device}
              <li class="list-group-item">
                <input type="checkbox" name="boot_device_enabled[]" value="{$device.name}" {if $device.enabled}checked{/if} style="margin-right: 10px;">
                <input type="hidden" name="boot_device_order[]" value="{$device.name}">
                <i class="fas fa-arrows-alt-v" style="margin-right: 10px; cursor: grab;"></i>
                {$device.name}
              </li>
            {foreachelse}
              <li class="list-group-item text-muted">No configurable boot devices found for this VM. This might indicate an issue or a very basic VM configuration.</li>
            {/foreach}
          </ul>
          {if $legacy_boot}
            <div class="alert alert-info small">
                Your VM seems to be using a legacy boot order format (e.g., "cdn").
                Saving a new order will convert it to the modern format (e.g., "order=ide2;scsi0;net0").
                Common legacy options are mapped as: 'c' to ide2 (CD/DVD), 'd' to scsi0 (first SCSI disk), 'n' to net0 (first network card).
                Adjust if your primary disk or network interface is different.
            </div>
          {/if}
        </div>
      </div>

      <div class="form-actions text-right">
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-save"></i> Save Boot Order
        </button>
        <a href="clientarea.php?action=productdetails&amp;id={$params.serviceid}" class="btn btn-secondary">
          Cancel
        </a>
      </div>
    </form>

    {* jQuery UI is often included with WHMCS themes, but ensure it is *}
    <link rel="stylesheet" href="//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
    <script>
      $(function() {
        $("#boot-device-list").sortable({
          axis: "y",
          handle: ".fa-arrows-alt-v",
          update: function(event, ui) {
            // Update hidden input order if needed, though direct form submission of visible order is fine
            $('#boot-device-list li').each(function(index) {
              $(this).find('input[name="boot_device_order[]"]').val($(this).find('input[name="boot_device_enabled[]"]').val());
            });
          }
        });
        // Ensure that when an item is dragged, its corresponding hidden input value is also updated
        // This is mostly handled by the name="boot_device_order[]" being present on each item.
        // The server will process them in the order they appear in the POST request.
      });
    </script>

  {else}
    <p class="text-danger">Could not load boot order management interface due to the error mentioned above.</p>
  {/if}
</div>
