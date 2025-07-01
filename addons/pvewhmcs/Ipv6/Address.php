<?php

namespace PveWhmcs\Ipv6;

class Address
{
    private const ERROR_ADDR_FORMAT = 'Invalid IPv6 address format';

    private $address;

    private function __construct(string $address)
    {
        $this->address = $address;
    }

    public static function fromString(string $data): self
    {
        $binaryAddress = inet_pton($data);
        if ($binaryAddress === false) {
            throw new \InvalidArgumentException(self::ERROR_ADDR_FORMAT);
        }
        return new self($binaryAddress);
    }

    public static function fromBinary(string $data): self
    {
        // Assuming $data is already a valid binary representation of an IPv6 address
        // No explicit validation here, but inet_ntop will fail if it's not valid
        return new self($data);
    }

    public function toString(): string
    {
        $stringAddress = inet_ntop($this->address);
        if ($stringAddress === false) {
            // This should ideally not happen if the address was validated correctly upon creation
            throw new \RuntimeException('Failed to convert binary IP to string representation');
        }
        return $stringAddress;
    }

    public function toBinary(): string
    {
        return $this->address;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
