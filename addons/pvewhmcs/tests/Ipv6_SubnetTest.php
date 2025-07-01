<?php

use PHPUnit\Framework\TestCase;
use PveWhmcs\Ipv6\Address;
use PveWhmcs\Ipv6\Subnet;

require_once __DIR__ . '/../Ipv6/Address.php';
require_once __DIR__ . '/../Ipv6/Subnet.php';

class Ipv6_SubnetTest extends TestCase
{
    public function testFromStringValid()
    {
        $subnet1 = Subnet::fromString('2001:db8:cafe::/48');
        $this->assertInstanceOf(Subnet::class, $subnet1);
        $this->assertEquals('2001:db8:cafe::', $subnet1->getNetwork()->toString());
        $this->assertEquals(48, $subnet1->getPrefix());

        $subnet2 = Subnet::fromString('2001:db8:abcd:1234::/64');
        $this->assertInstanceOf(Subnet::class, $subnet2);
        $this->assertEquals('2001:db8:abcd:1234::', $subnet2->getNetwork()->toString());
        $this->assertEquals(64, $subnet2->getPrefix());
        
        // Test with host bits set, should normalize to network address
        $subnet3 = Subnet::fromString('2001:db8:aaaa:bbbb:cccc:dddd:eeee:ffff/64');
        $this->assertInstanceOf(Subnet::class, $subnet3);
        $this->assertEquals('2001:db8:aaaa:bbbb::', $subnet3->getNetwork()->toString());
        $this->assertEquals(64, $subnet3->getPrefix());

        $subnet4 = Subnet::fromString('::1/128');
        $this->assertInstanceOf(Subnet::class, $subnet4);
        $this->assertEquals('::1', $subnet4->getNetwork()->toString());
        $this->assertEquals(128, $subnet4->getPrefix());
    }

    public function testFromStringInvalidCidrMissingPrefix()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid CIDR format');
        Subnet::fromString('2001:db8:cafe::');
    }

    public function testFromStringInvalidIp()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid network address format');
        Subnet::fromString('invalid-ip/48');
    }

    public function testFromStringInvalidPrefixTooLow()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix must be between 0 and 128');
        Subnet::fromString('2001:db8::/-1');
    }

    public function testFromStringInvalidPrefixTooHigh()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix must be between 0 and 128');
        Subnet::fromString('2001:db8::/129');
    }
    
    public function testFromStringNonCanonicalNetworkAddress()
    {
        // This is handled by the constructor logic which re-calculates the network address.
        // The fromString should still create a valid subnet object with the *correct* network address.
        $subnet = Subnet::fromString('2001:db8:cafe:0001::1234/48'); // Host bits are set
        $this->assertEquals('2001:db8:cafe::', $subnet->getNetwork()->toString(), "Network address should be normalized");
        $this->assertEquals(48, $subnet->getPrefix());
    }


    public function testGetNetworkAndPrefix()
    {
        $networkAddr = Address::fromString('2001:db8:aaaa::');
        $prefix = 64;
        $subnet = new Subnet($networkAddr, $prefix);
        $this->assertSame($networkAddr, $subnet->getNetwork()); // Should be the same object initially
        $this->assertEquals($prefix, $subnet->getPrefix());
    }

    public function testGetNetmask()
    {
        // /64
        $subnet64 = Subnet::fromString('2001:db8::/64');
        $this->assertEquals('ffff:ffff:ffff:ffff::', $subnet64->getNetmask()->toString());

        // /48
        $subnet48 = Subnet::fromString('2001:db8:cafe::/48');
        $this->assertEquals('ffff:ffff:ffff::', $subnet48->getNetmask()->toString());

        // /128
        $subnet128 = Subnet::fromString('::1/128');
        $this->assertEquals('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', $subnet128->getNetmask()->toString());
        
        // /0
        $subnet0 = Subnet::fromString('::/0');
        $this->assertEquals('::', $subnet0->getNetmask()->toString());

        // /57 (odd prefix)
        $subnet57 = Subnet::fromString('2001:db8:a::/57');
        $this->assertEquals('ffff:ffff:ffff:fe00::', $subnet57->getNetmask()->toString());
    }

    public function testGetFirstAndLastHostAddr()
    {
        // /64 subnet
        $subnet64 = Subnet::fromString('2001:db8:abcd:1234::/64');
        $this->assertEquals('2001:db8:abcd:1234::1', $subnet64->getFirstHostAddr()->toString(), "/64 First Host");
        $this->assertEquals('2001:db8:abcd:1234:ffff:ffff:ffff:ffff', $subnet64->getLastHostAddr()->toString(), "/64 Last Host");

        // /128 subnet
        $subnet128 = Subnet::fromString('2001:db8:dead:beef::1/128');
        $this->assertEquals('2001:db8:dead:beef::1', $subnet128->getFirstHostAddr()->toString(), "/128 First Host (same as network)");
        $this->assertEquals('2001:db8:dead:beef::1', $subnet128->getLastHostAddr()->toString(), "/128 Last Host (same as network)");

        // /127 subnet (RFC 3627 implies both are usable host addresses)
        $subnet127 = Subnet::fromString('2001:db8:dead:beef::/127');
        $this->assertEquals('2001:db8:dead:beef::', $subnet127->getFirstHostAddr()->toString(), "/127 First Host");
        $this->assertEquals('2001:db8:dead:beef::1', $subnet127->getLastHostAddr()->toString(), "/127 Last Host");

        // Larger subnet /48
        $subnet48 = Subnet::fromString('2001:db8:cafe::/48');
        $this->assertEquals('2001:db8:cafe::1', $subnet48->getFirstHostAddr()->toString(), "/48 First Host");
        $this->assertEquals('2001:db8:cafe:ffff:ffff:ffff:ffff:ffff', $subnet48->getLastHostAddr()->toString(), "/48 Last Host");
    }

    public function testContains()
    {
        $subnet = Subnet::fromString('2001:db8:cafe::/48');

        // Addresses within the subnet
        $this->assertTrue($subnet->contains(Address::fromString('2001:db8:cafe::1')));
        $this->assertTrue($subnet->contains(Address::fromString('2001:db8:cafe:ffff:ffff:ffff:ffff:ffff')));
        $this->assertTrue($subnet->contains(Address::fromString('2001:db8:cafe:1234:5678:9abc:def0:1234')));
        $this->assertTrue($subnet->contains(Address::fromString('2001:db8:cafe::'))); // Network address itself

        // Addresses outside the subnet
        $this->assertFalse($subnet->contains(Address::fromString('2001:db8:caff::1'))); // Different subnet
        $this->assertFalse($subnet->contains(Address::fromString('2001:db9::1')));      // Different subnet
        $this->assertFalse($subnet->contains(Address::fromString('::1')));
    }

    public function testGetTotalHosts()
    {
        // /64
        $subnet64 = Subnet::fromString('2001:db8::/64');
        // 2^(128-64) = 2^64
        $this->assertEquals(gmp_strval(gmp_pow(2, 64)), $subnet64->getTotalHosts());

        // /128
        $subnet128 = Subnet::fromString('::1/128');
        $this->assertEquals('1', $subnet128->getTotalHosts());

        // /127 (RFC 3627, two addresses, both typically usable)
        $subnet127 = Subnet::fromString('fe80::/127');
        $this->assertEquals('2', $subnet127->getTotalHosts());

        // /48
        $subnet48 = Subnet::fromString('2001:db8:cafe::/48');
        // 2^(128-48) = 2^80
        $this->assertEquals(gmp_strval(gmp_pow(2, 80)), $subnet48->getTotalHosts());
        
        // /0 (all IPv6 addresses)
        $subnet0 = Subnet::fromString('::/0');
        $this->assertEquals(gmp_strval(gmp_pow(2,128)), $subnet0->getTotalHosts());

    }

    public function testToStringMagicMethod()
    {
        $cidr = '2001:db8:aaaa:bbbb::/64';
        $subnet = Subnet::fromString($cidr);
        $this->assertEquals($cidr, (string)$subnet);

        $cidr2 = '::1/128';
        $subnet2 = Subnet::fromString($cidr2);
        $this->assertEquals('::1/128', (string)$subnet2);
    }
    
    public function testConstructorWithNonCanonicalNetworkAddress()
    {
        // Provide an address that has host bits set for the given prefix
        $nonCanonicalAddr = Address::fromString('2001:db8:cafe:0001::1234'); // for a /48
        $prefix = 48;
        
        // The constructor should correct this to the actual network address
        $subnet = new Subnet($nonCanonicalAddr, $prefix);
        
        $this->assertEquals('2001:db8:cafe::', $subnet->getNetwork()->toString());
        $this->assertEquals(48, $subnet->getPrefix());
    }
}

?>
