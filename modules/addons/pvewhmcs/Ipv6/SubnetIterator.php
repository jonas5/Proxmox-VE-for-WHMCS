<?php

namespace PveWhmcs\Ipv6;

require_once __DIR__ . '/Subnet.php';
require_once __DIR__ . '/Address.php';

use Iterator;
use GMP;

class SubnetIterator implements Iterator
{
    private GMP $firstHostGmp;
    private GMP $lastHostGmp;
    private GMP $currentGmp;
    private GMP $keyGmp; // To store the offset from the start

    public function __construct(Subnet $subnet)
    {
        $this->firstHostGmp = gmp_init(bin2hex($subnet->getFirstHostAddr()->toBinary()), 16);
        $this->lastHostGmp = gmp_init(bin2hex($subnet->getLastHostAddr()->toBinary()), 16);
        
        // Initialize current and key
        $this->rewind();
    }

    public function rewind(): void
    {
        $this->currentGmp = $this->firstHostGmp;
        $this->keyGmp = gmp_init(0); // Key is 0 for the first element
    }

    public function current(): Address
    {
        // Pad with leading zeros if necessary to ensure it's 32 hex characters (16 bytes)
        $hexAddress = str_pad(gmp_strval($this->currentGmp, 16), 32, '0', STR_PAD_LEFT);
        return Address::fromBinary(hex2bin($hexAddress));
    }

    public function key(): string
    {
        // Return the key as a string, representing the offset from the first address
        return gmp_strval($this->keyGmp);
    }

    public function next(): void
    {
        if ($this->valid()) { // Only increment if current is valid or will become valid
            $this->currentGmp = gmp_add($this->currentGmp, 1);
            $this->keyGmp = gmp_add($this->keyGmp, 1);
        }
    }

    public function valid(): bool
    {
        // The iterator is valid if the current GMP value is less than or equal to the last host GMP value.
        // Also handles the case where firstHostGmp might be greater than lastHostGmp (e.g. for an empty range, though Subnet class tries to prevent this)
        if (gmp_cmp($this->firstHostGmp, $this->lastHostGmp) > 0 && gmp_cmp($this->currentGmp, $this->firstHostGmp) === 0) {
             // This case implies an empty or invalid range where first > last.
             // If current is still at first, it's invalid.
             // This check is important if Subnet could theoretically produce first > last.
             // Based on current Subnet logic, for /128, first == last.
            return false;
        }
        return gmp_cmp($this->currentGmp, $this->lastHostGmp) <= 0;
    }
}

?>
