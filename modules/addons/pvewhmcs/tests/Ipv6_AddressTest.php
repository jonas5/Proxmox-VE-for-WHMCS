<?php

use PHPUnit\Framework\TestCase;
use PveWhmcs\Ipv6\Address;

require_once __DIR__ . '/../Ipv6/Address.php';

class Ipv6_AddressTest extends TestCase
{
    public function testFromStringValid()
    {
        $addr1 = Address::fromString('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
        $this->assertInstanceOf(Address::class, $addr1);
        $this->assertEquals('2001:db8:85a3::8a2e:370:7334', $addr1->toString()); // Test compression

        $addr2 = Address::fromString('::1');
        $this->assertInstanceOf(Address::class, $addr2);
        $this->assertEquals('::1', $addr2->toString());

        $addr3 = Address::fromString('2001:db8::');
        $this->assertInstanceOf(Address::class, $addr3);
        $this->assertEquals('2001:db8::', $addr3->toString());
        
        $addr4 = Address::fromString('fe80::1234:5678:9abc:def0');
        $this->assertInstanceOf(Address::class, $addr4);
        $this->assertEquals('fe80::1234:5678:9abc:def0', $addr4->toString());
    }

    public function testFromStringInvalid()
    {
        $this->expectException(\InvalidArgumentException::class);
        Address::fromString('invalid-ipv6');
    }

    public function testFromStringInvalidFormatTooManyColons()
    {
        $this->expectException(\InvalidArgumentException::class);
        Address::fromString('2001:0db8::85a3::8a2e::0370:7334');
    }

    public function testFromStringInvalidChars()
    {
        $this->expectException(\InvalidArgumentException::class);
        Address::fromString('2001:0db8:85a3:000g:0000:8a2e:0370:7334');
    }
    
    public function testFromStringInvalidTooShort()
    {
        $this->expectException(\InvalidArgumentException::class);
        Address::fromString('2001:db8::123');
    }

    public function testToAndFromBinary()
    {
        $originalString = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';
        $addr1 = Address::fromString($originalString);
        $binary = $addr1->toBinary();

        $addr2 = Address::fromBinary($binary);
        $this->assertEquals($addr1->toString(), $addr2->toString());
        $this->assertEquals($binary, $addr2->toBinary());
    }

    public function testToStringOutput()
    {
        $addr = Address::fromString('2001:0DB8:0000:0000:0000:0000:1428:57ab');
        $this->assertEquals('2001:db8::1428:57ab', $addr->toString());

        $addrShort = Address::fromString('::1');
        $this->assertEquals('::1', $addrShort->toString());
    }

    public function testMagicToString()
    {
        $addrString = 'fe80::dead:beef:cafe:babe';
        $addr = Address::fromString($addrString);
        $this->assertEquals($addrString, (string)$addr);
    }
}

?>
