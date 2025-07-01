<?php

namespace PveWhmcs\Ipv6;

require_once __DIR__ . '/Address.php';

use GMP;

class Subnet
{
    private const ERROR_NETWORK_FORMAT = 'Invalid network address format';
    private const ERROR_CIDR_FORMAT = 'Invalid CIDR format';
    private const ERROR_PREFIX_RANGE = 'Prefix must be between 0 and 128';

    private Address $network;
    private int $prefix;

    public function __construct(Address $network, int $prefix)
    {
        if ($prefix < 0 || $prefix > 128) {
            throw new \InvalidArgumentException(self::ERROR_PREFIX_RANGE);
        }

        // Ensure the network address is actually a network address (host bits are zero)
        $netmask = self::calculateNetmask($prefix);
        $networkBinary = gmp_init(bin2hex($network->toBinary()), 16);
        $netmaskBinary = gmp_init(bin2hex($netmask->toBinary()), 16);
        
        $calculatedNetwork = gmp_and($networkBinary, $netmaskBinary);

        if (gmp_cmp($networkBinary, $calculatedNetwork) !== 0) {
            throw new \InvalidArgumentException("The provided address is not a valid network address for the given prefix.");
        }
        
        $this->network = Address::fromBinary(hex2bin(str_pad(gmp_strval($calculatedNetwork, 16), 32, '0', STR_PAD_LEFT)));
        $this->prefix = $prefix;
    }

    public static function fromString(string $data): self
    {
        if (!str_contains($data, '/')) {
            throw new \InvalidArgumentException(self::ERROR_CIDR_FORMAT);
        }

        list($ipString, $prefixString) = explode('/', $data, 2);

        if (!filter_var($prefixString, FILTER_VALIDATE_INT) || $prefixString < 0 || $prefixString > 128) {
            throw new \InvalidArgumentException(self::ERROR_PREFIX_RANGE);
        }
        $prefix = (int)$prefixString;

        try {
            $address = Address::fromString($ipString);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(self::ERROR_NETWORK_FORMAT . ': ' . $e->getMessage());
        }
        
        // Ensure the provided IP is the actual network address for the given CIDR
        $netmask = self::calculateNetmask($prefix);
        $addressBinary = gmp_init(bin2hex($address->toBinary()), 16);
        $netmaskBinary = gmp_init(bin2hex($netmask->toBinary()), 16);
        $networkAddressBinary = gmp_and($addressBinary, $netmaskBinary);
        
        $networkAddress = Address::fromBinary(hex2bin(str_pad(gmp_strval($networkAddressBinary, 16), 32, '0', STR_PAD_LEFT)));

        return new self($networkAddress, $prefix);
    }

    public function getNetwork(): Address
    {
        return $this->network;
    }

    public function getPrefix(): int
    {
        return $this->prefix;
    }

    private static function calculateNetmask(int $prefix): Address
    {
        if ($prefix < 0 || $prefix > 128) {
            throw new \InvalidArgumentException(self::ERROR_PREFIX_RANGE);
        }

        $maskHex = str_repeat('f', $prefix / 4);
        $remainder = $prefix % 4;
        if ($remainder !== 0) {
            $maskHex .= dechex((0xF000 >> ($remainder * 4 - 4)) & 0xF);
        }
        $maskHex = str_pad($maskHex, 32, '0', STR_PAD_RIGHT);
        
        // Correcting the hex representation for partial nibbles
        if ($remainder !==0 ){
            $fullBytes = intdiv($prefix, 8);
            $remainingBits = $prefix % 8;
            $binaryMask = str_repeat(chr(255), $fullBytes);
            if($remainingBits > 0){
                $binaryMask .= chr((0xFF << (8-$remainingBits)) & 0xFF) ;
            }
            $binaryMask = str_pad($binaryMask, 16, chr(0), STR_PAD_RIGHT);
            return Address::fromBinary($binaryMask);
        }


        return Address::fromBinary(hex2bin($maskHex));
    }
    
    public function getNetmask(): Address
    {
        return self::calculateNetmask($this->prefix);
    }

    public function getFirstHostAddr(): Address
    {
        if ($this->prefix === 128) {
             // For /128, the network address is the only address and can be considered the first "host"
            return $this->network;
        }
        if ($this->prefix === 127) {
            // For /127, as per RFC 3627, these are often point-to-point links
            // and the lower address can be considered a host address.
            return $this->network;
        }

        $networkBinary = gmp_init(bin2hex($this->network->toBinary()), 16);
        $firstHostBinary = gmp_add($networkBinary, 1);
        return Address::fromBinary(hex2bin(str_pad(gmp_strval($firstHostBinary, 16), 32, '0', STR_PAD_LEFT)));
    }

    public function getLastHostAddr(): Address
    {
        if ($this->prefix === 128) {
            return $this->network; // Only one address
        }
         if ($this->prefix === 127) {
            // For /127, the higher address is the other host.
            $networkBinary = gmp_init(bin2hex($this->network->toBinary()), 16);
            $lastHostBinary = gmp_add($networkBinary, 1); // network is X, last host is X+1
            return Address::fromBinary(hex2bin(str_pad(gmp_strval($lastHostBinary, 16), 32, '0', STR_PAD_LEFT)));
        }

        $networkBinary = gmp_init(bin2hex($this->network->toBinary()), 16);
        $netmaskBinary = gmp_init(bin2hex($this->getNetmask()->toBinary()), 16);
        
        // Calculate broadcast address (network | ~netmask)
        // ~netmask (inverse of netmask)
        $invertedNetmask = gmp_xor($netmaskBinary, gmp_init(str_repeat('f', 32), 16));
        $broadcastAddressBinary = gmp_or($networkBinary, $invertedNetmask);

        // Last host is broadcast - 1 (for subnets larger than /127)
        $lastHostBinary = gmp_sub($broadcastAddressBinary, 1);
        // However, if the prefix is such that broadcast -1 is less than network + 1 (e.g. prefix 127),
        // this logic breaks.
        // The "broadcast address" is the last address in the range.
        // For IPv6, the concept of "broadcast address" and "network address" being unusable is different.
        // Generally, all addresses in the range are usable unless specifically reserved.
        // The last address in the range is simply network + (2^(128-prefix)) - 1

        $hostBits = 128 - $this->prefix;
        $numberOfHosts = gmp_pow(2, $hostBits);
        $lastAddressInRange = gmp_add($networkBinary, gmp_sub($numberOfHosts, 1));

        return Address::fromBinary(hex2bin(str_pad(gmp_strval($lastAddressInRange, 16), 32, '0', STR_PAD_LEFT)));
    }

    public function contains(Address $address): bool
    {
        $addressBinary = gmp_init(bin2hex($address->toBinary()), 16);
        $networkBinary = gmp_init(bin2hex($this->network->toBinary()), 16);
        $netmaskBinary = gmp_init(bin2hex($this->getNetmask()->toBinary()), 16);

        return gmp_cmp(gmp_and($addressBinary, $netmaskBinary), $networkBinary) === 0;
    }

    public function getTotalHosts(): string
    {
        if ($this->prefix === 128) {
            return '1';
        }
        // For /127, RFC 3627 states two addresses, both usable.
        if ($this->prefix === 127) {
             return '2';
        }
        // In IPv6, typically all addresses in a subnet are usable.
        // The "network" address and "broadcast" address concepts from IPv4 don't directly map to unusable addresses.
        // However, sometimes the lowest address is used for subnet router anycast.
        // For simplicity, we calculate total addresses in the range.
        $hostBits = 128 - $this->prefix;
        return gmp_strval(gmp_pow(2, $hostBits));
    }

    public function __toString(): string
    {
        return $this->network->toString() . '/' . $this->prefix;
    }
}

?>
