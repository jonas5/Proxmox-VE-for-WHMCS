## Client Area Features

This module enhances the WHMCS client area with several powerful features for managing Proxmox VE virtual machines:

### 1. ISO Loader

Clients can easily mount and unmount ISO images to their QEMU/KVM virtual machines. This is useful for OS installations, recovery tasks, or running diagnostic tools directly from an ISO.

*   **Mount ISO:** Select an available ISO image from your Proxmox storage (default 'local' storage is assumed by the module) and a target virtual CD/DVD drive (e.g., ide2).
*   **Force Boot Options:** When mounting, clients can optionally choose to:
    *   Force the VM to enter the BIOS/UEFI setup screen on the next reboot.
    *   Temporarily set the CD/DVD drive as the primary boot device for the next boot.
*   **Unmount ISO:** Eject the currently mounted ISO image.
*   **Status:** The interface displays the currently mounted ISO and the drive it's on.

### 2. Kernel/OS Type Configuration

For QEMU/KVM virtual machines, clients can adjust the OS Type and BIOS/loader settings. This influences how Proxmox optimizes the VM and is crucial for compatibility with certain operating systems, especially Windows versions requiring UEFI.

*   **OS Type Selection:** Choose from a list of common OS types (e.g., various Linux versions, Windows 10/11, Solaris).
*   **BIOS/Loader Adjustment:** Selecting an OS type automatically configures the appropriate BIOS (SeaBIOS for most Linux/other, OVMF for UEFI-based Windows installs).
*   **Reboot Required:** Changes to this configuration typically require a VM reboot to take effect.
*   **UEFI Support:** For Windows UEFI (OVMF), the module configures the necessary settings. Ensure your VM template or ISO is UEFI compatible.

### 3. Boot Order Configuration

Clients can define the boot sequence for their QEMU/KVM virtual machines, specifying which virtual devices (disks, CD/DVD drives, network interfaces) the VM should attempt to boot from and in what priority.

*   **View Current Order:** The current boot order as configured in Proxmox is displayed.
*   **Enable/Disable Devices:** Checkboxes allow clients to include or exclude specific devices from the boot sequence.
*   **Re-order Devices:** A drag-and-drop interface allows clients to easily change the priority of enabled boot devices.
*   **Save Changes:** The new boot order is saved to the VM's configuration in Proxmox.
*   **Legacy Support:** The interface can interpret and update older Proxmox boot order formats (e.g., "cdn").
