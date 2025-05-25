<?php

use PHPUnit\Framework\TestCase;
use PveWhmcs\Ipv6\Address;
use PveWhmcs\Ipv6\Subnet;
use PveWhmcs\Ipv6\SubnetIterator;

require_once __DIR__ . '/../Ipv6/Address.php';
require_once __DIR__ . '/../Ipv6/Subnet.php';
require_once __DIR__ . '/../Ipv6/SubnetIterator.php';

class Ipv6_SubnetIteratorTest extends TestCase
{
    public function testIterationOverSmallSubnet()
    {
        // A /125 subnet has 2^(128-125) = 2^3 = 8 addresses.
        // FirstHostAddr and LastHostAddr logic in Subnet.php defines the iterable range.
        // For a general case like /125, first is network+1, last is network + 8 - 1.
        $subnet = Subnet::fromString('2001:db8::/125');
        $iterator = new SubnetIterator($subnet);

        $expectedAddresses = [
            '2001:db8::1',
            '2001:db8::2',
            '2001:db8::3',
            '2001:db8::4',
            '2001:db8::5',
            '2001:db8::6',
            '2001:db8::7',
        ];
        // Note: Subnet's getLastHostAddr() for a /125 ('2001:db8::/125') gives '2001:db8::7'
        // The first host is '2001:db8::1'

        $count = 0;
        foreach ($iterator as $key => $address) {
            $this->assertInstanceOf(Address::class, $address);
            $this->assertEquals($expectedAddresses[$count], $address->toString(), "Address mismatch at index " . $count);
            $this->assertEquals((string)$count, $key, "Key mismatch at index " . $count);
            $count++;
        }
        $this->assertEquals(count($expectedAddresses), $count, "Iterator didn't yield the expected number of addresses.");
    }

    public function testIterationOver128Subnet()
    {
        $subnet = Subnet::fromString('2001:db8:dead:beef::1/128');
        $iterator = new SubnetIterator($subnet);

        $count = 0;
        $expectedAddress = '2001:db8:dead:beef::1';

        foreach ($iterator as $key => $address) {
            $this->assertInstanceOf(Address::class, $address);
            $this->assertEquals($expectedAddress, $address->toString());
            $this->assertEquals('0', $key); // Key should be '0' for the single address
            $count++;
        }
        $this->assertEquals(1, $count, "Iterator for /128 should yield exactly one address.");
    }

    public function testIterationOver127Subnet()
    {
        // As per Subnet.php logic for /127:
        // getFirstHostAddr() is the network address itself.
        // getLastHostAddr() is network address + 1.
        $subnet = Subnet::fromString('2001:db8:dead:beef::ab/127'); // Using 'ab' to ensure it's not ::0 or ::1
        $iterator = new SubnetIterator($subnet);
        
        $expectedAddresses = [
            '2001:db8:dead:beef::ab',
            '2001:db8:dead:beef::ac', // ab + 1 = ac
        ];

        $count = 0;
        foreach ($iterator as $key => $address) {
            $this->assertInstanceOf(Address::class, $address);
            $this->assertEquals($expectedAddresses[$count], $address->toString(), "Address mismatch for /127 at index " . $count);
            $this->assertEquals((string)$count, $key);
            $count++;
        }
        $this->assertEquals(2, $count, "Iterator for /127 should yield two addresses.");
    }

    public function testRewindAndValid()
    {
        $subnet = Subnet::fromString('2001:db8:test::/126'); // 4 addresses: ::1, ::2, ::3
        $iterator = new SubnetIterator($subnet);

        // First iteration
        $iterations1 = 0;
        $currentAddresses1 = [];
        while ($iterator->valid()) {
            $currentAddresses1[] = $iterator->current()->toString();
            $iterator->next();
            $iterations1++;
        }
        $this->assertEquals(3, $iterations1); // ::1, ::2, ::3
        $this->assertEquals(['2001:db8:test::1', '2001:db8:test::2', '2001:db8:test::3'], $currentAddresses1);


        // After iteration, valid should be false
        $this->assertFalse($iterator->valid());

        // Rewind
        $iterator->rewind();
        $this->assertTrue($iterator->valid(), "Iterator should be valid after rewind if range is not empty.");

        // Second iteration
        $iterations2 = 0;
        $currentAddresses2 = [];
        foreach ($iterator as $address) { // Using foreach also tests rewind implicitly if not already called
            $currentAddresses2[] = $address->toString();
            $iterations2++;
        }
        $this->assertEquals(3, $iterations2);
        $this->assertEquals(['2001:db8:test::1', '2001:db8:test::2', '2001:db8:test::3'], $currentAddresses2);
    }
    
    public function testKeyAndCurrent()
    {
        $subnet = Subnet::fromString('2001:db8:f::/126'); // ::1, ::2, ::3
        $iterator = new SubnetIterator($subnet);

        $this->assertTrue($iterator->valid());
        $this->assertEquals('0', $iterator->key());
        $this->assertEquals('2001:db8:f::1', $iterator->current()->toString());

        $iterator->next();
        $this->assertTrue($iterator->valid());
        $this->assertEquals('1', $iterator->key());
        $this->assertEquals('2001:db8:f::2', $iterator->current()->toString());

        $iterator->next();
        $this->assertTrue($iterator->valid());
        $this->assertEquals('2', $iterator->key());
        $this->assertEquals('2001:db8:f::3', $iterator->current()->toString());
        
        $iterator->next();
        $this->assertFalse($iterator->valid());
    }

    // The Subnet class as implemented should not produce an empty range where getFirstHostAddr > getLastHostAddr.
    // For a /128, first and last are the same.
    // For other valid prefixes, last > first.
    // If Subnet logic changes, this test might become relevant.
    // For now, we can test with a /128 as the "smallest non-empty range".
    public function testIterationOverMinimalRange()
    {
        $subnet = Subnet::fromString('2001:db8:minimal::77/128');
        $iterator = new SubnetIterator($subnet);
        
        $count = 0;
        $this->assertTrue($iterator->valid());
        $currentAddr = $iterator->current();
        $this->assertEquals('2001:db8:minimal::77', $currentAddr->toString());
        $this->assertEquals('0', $iterator->key());
        $count++;
        
        $iterator->next();
        $this->assertFalse($iterator->valid());
        $this->assertEquals(1, $count);
    }
}

?>
