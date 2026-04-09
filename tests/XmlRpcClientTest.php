<?php

namespace Hardtail\XmlRpcClient\Tests;

use DOMDocument;
use Hardtail\XmlRpcClient\Exceptions\XmlRpcException;
use Hardtail\XmlRpcClient\Exceptions\XmlRpcFaultException;
use Hardtail\XmlRpcClient\XmlRpcClient;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class XmlRpcClientTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\Hardtail\XmlRpcClient\XmlRpcClientServiceProvider::class];
    }

    protected function makeClient(string $endpoint = 'https://example.com/xmlrpc.php', ?string $user = null, ?string $pass = null): XmlRpcClient
    {
        return new XmlRpcClient($endpoint, $user, $pass);
    }

    protected function fakeXmlRpcResponse(string $xml): void
    {
        Http::fake([
            '*' => Http::response($xml, 200, ['Content-Type' => 'text/xml']),
        ]);
    }

    // -------------------------------------------------------
    // Request building
    // -------------------------------------------------------

    public function test_call_sends_correct_xml_for_string_params(): void
    {
        $this->fakeXmlRpcResponse($this->simpleStringResponse('OK'));
        $client = $this->makeClient();

        $client->call('system.listMethods', 'arg1', 'arg2');

        Http::assertSent(function ($request) {
            $body = $request->body();
            $doc = new DOMDocument();
            $doc->loadXML($body);

            $methodName = $doc->getElementsByTagName('methodName')->item(0)->textContent;
            $this->assertEquals('system.listMethods', $methodName);

            $params = $doc->getElementsByTagName('param');
            $this->assertEquals(2, $params->length);

            $val1 = $params->item(0)->getElementsByTagName('string')->item(0)->textContent;
            $val2 = $params->item(1)->getElementsByTagName('string')->item(0)->textContent;
            $this->assertEquals('arg1', $val1);
            $this->assertEquals('arg2', $val2);

            return true;
        });
    }

    public function test_call_sends_correct_xml_for_int_param(): void
    {
        $this->fakeXmlRpcResponse($this->simpleStringResponse('OK'));
        $client = $this->makeClient();

        $client->call('test.method', 42);

        Http::assertSent(function ($request) {
            $doc = new DOMDocument();
            $doc->loadXML($request->body());
            $int = $doc->getElementsByTagName('int')->item(0);
            $this->assertNotNull($int);
            $this->assertEquals('42', $int->textContent);
            return true;
        });
    }

    public function test_call_sends_correct_xml_for_boolean_param(): void
    {
        $this->fakeXmlRpcResponse($this->simpleStringResponse('OK'));
        $client = $this->makeClient();

        $client->call('test.method', true, false);

        Http::assertSent(function ($request) {
            $doc = new DOMDocument();
            $doc->loadXML($request->body());
            $booleans = $doc->getElementsByTagName('boolean');
            $this->assertEquals(2, $booleans->length);
            $this->assertEquals('1', $booleans->item(0)->textContent);
            $this->assertEquals('0', $booleans->item(1)->textContent);
            return true;
        });
    }

    public function test_call_sends_correct_xml_for_double_param(): void
    {
        $this->fakeXmlRpcResponse($this->simpleStringResponse('OK'));
        $client = $this->makeClient();

        $client->call('test.method', 3.14);

        Http::assertSent(function ($request) {
            $doc = new DOMDocument();
            $doc->loadXML($request->body());
            $double = $doc->getElementsByTagName('double')->item(0);
            $this->assertNotNull($double);
            $this->assertEquals('3.14', $double->textContent);
            return true;
        });
    }

    public function test_call_sends_struct_for_associative_array(): void
    {
        $this->fakeXmlRpcResponse($this->simpleStringResponse('OK'));
        $client = $this->makeClient();

        $client->call('test.method', ['name' => 'John', 'age' => 30]);

        Http::assertSent(function ($request) {
            $doc = new DOMDocument();
            $doc->loadXML($request->body());
            $struct = $doc->getElementsByTagName('struct')->item(0);
            $this->assertNotNull($struct);

            $members = $doc->getElementsByTagName('member');
            $this->assertEquals(2, $members->length);

            $names = $doc->getElementsByTagName('name');
            $this->assertEquals('name', $names->item(0)->textContent);
            $this->assertEquals('age', $names->item(1)->textContent);

            return true;
        });
    }

    public function test_call_sends_array_for_sequential_array(): void
    {
        $this->fakeXmlRpcResponse($this->simpleStringResponse('OK'));
        $client = $this->makeClient();

        $client->call('test.method', ['a', 'b', 'c']);

        Http::assertSent(function ($request) {
            $doc = new DOMDocument();
            $doc->loadXML($request->body());
            $array = $doc->getElementsByTagName('array')->item(0);
            $this->assertNotNull($array);

            $data = $doc->getElementsByTagName('data')->item(0);
            $this->assertNotNull($data);

            return true;
        });
    }

    public function test_call_sends_no_params_element_when_empty(): void
    {
        $this->fakeXmlRpcResponse($this->simpleStringResponse('OK'));
        $client = $this->makeClient();

        $client->call('system.listMethods');

        Http::assertSent(function ($request) {
            $doc = new DOMDocument();
            $doc->loadXML($request->body());
            $params = $doc->getElementsByTagName('params');
            $this->assertEquals(0, $params->length);
            return true;
        });
    }

    // -------------------------------------------------------
    // Response parsing
    // -------------------------------------------------------

    public function test_parses_string_response(): void
    {
        $this->fakeXmlRpcResponse($this->simpleStringResponse('Hello World'));
        $client = $this->makeClient();

        $result = $client->call('test.method');
        $this->assertEquals('Hello World', $result);
    }

    public function test_parses_int_response(): void
    {
        $xml = '<?xml version="1.0"?>
        <methodResponse>
            <params><param><value><int>42</int></value></param></params>
        </methodResponse>';

        $this->fakeXmlRpcResponse($xml);
        $result = $this->makeClient()->call('test.method');

        $this->assertSame(42, $result);
    }

    public function test_parses_boolean_response(): void
    {
        $xml = '<?xml version="1.0"?>
        <methodResponse>
            <params><param><value><boolean>1</boolean></value></param></params>
        </methodResponse>';

        $this->fakeXmlRpcResponse($xml);
        $result = $this->makeClient()->call('test.method');

        $this->assertTrue($result);
    }

    public function test_parses_double_response(): void
    {
        $xml = '<?xml version="1.0"?>
        <methodResponse>
            <params><param><value><double>3.14</double></value></param></params>
        </methodResponse>';

        $this->fakeXmlRpcResponse($xml);
        $result = $this->makeClient()->call('test.method');

        $this->assertSame(3.14, $result);
    }

    public function test_parses_array_response(): void
    {
        $xml = '<?xml version="1.0"?>
        <methodResponse>
            <params><param><value>
                <array><data>
                    <value><string>one</string></value>
                    <value><string>two</string></value>
                    <value><int>3</int></value>
                </data></array>
            </value></param></params>
        </methodResponse>';

        $this->fakeXmlRpcResponse($xml);
        $result = $this->makeClient()->call('test.method');

        $this->assertEquals(['one', 'two', 3], $result);
    }

    public function test_parses_struct_response(): void
    {
        $xml = '<?xml version="1.0"?>
        <methodResponse>
            <params><param><value>
                <struct>
                    <member>
                        <name>name</name>
                        <value><string>John</string></value>
                    </member>
                    <member>
                        <name>age</name>
                        <value><int>30</int></value>
                    </member>
                </struct>
            </value></param></params>
        </methodResponse>';

        $this->fakeXmlRpcResponse($xml);
        $result = $this->makeClient()->call('test.method');

        $this->assertEquals(['name' => 'John', 'age' => 30], $result);
    }

    public function test_parses_nested_struct_in_array(): void
    {
        $xml = '<?xml version="1.0"?>
        <methodResponse>
            <params><param><value>
                <array><data>
                    <value><struct>
                        <member><name>id</name><value><int>1</int></value></member>
                        <member><name>label</name><value><string>first</string></value></member>
                    </struct></value>
                    <value><struct>
                        <member><name>id</name><value><int>2</int></value></member>
                        <member><name>label</name><value><string>second</string></value></member>
                    </struct></value>
                </data></array>
            </value></param></params>
        </methodResponse>';

        $this->fakeXmlRpcResponse($xml);
        $result = $this->makeClient()->call('test.method');

        $this->assertEquals([
            ['id' => 1, 'label' => 'first'],
            ['id' => 2, 'label' => 'second'],
        ], $result);
    }

    public function test_parses_nil_response(): void
    {
        $xml = '<?xml version="1.0"?>
        <methodResponse>
            <params><param><value><nil/></value></param></params>
        </methodResponse>';

        $this->fakeXmlRpcResponse($xml);
        $result = $this->makeClient()->call('test.method');

        $this->assertNull($result);
    }

    public function test_parses_untyped_value_as_string(): void
    {
        $xml = '<?xml version="1.0"?>
        <methodResponse>
            <params><param><value>plain text</value></param></params>
        </methodResponse>';

        $this->fakeXmlRpcResponse($xml);
        $result = $this->makeClient()->call('test.method');

        $this->assertEquals('plain text', $result);
    }

    public function test_returns_null_for_empty_response(): void
    {
        $xml = '<?xml version="1.0"?>
        <methodResponse><params></params></methodResponse>';

        $this->fakeXmlRpcResponse($xml);
        $result = $this->makeClient()->call('test.method');

        $this->assertNull($result);
    }

    // -------------------------------------------------------
    // Fault handling
    // -------------------------------------------------------

    public function test_throws_fault_exception_on_xmlrpc_fault(): void
    {
        $xml = '<?xml version="1.0"?>
        <methodResponse>
            <fault><value><struct>
                <member><name>faultCode</name><value><int>4</int></value></member>
                <member><name>faultString</name><value><string>Too many parameters.</string></value></member>
            </struct></value></fault>
        </methodResponse>';

        $this->fakeXmlRpcResponse($xml);

        try {
            $this->makeClient()->call('test.method');
            $this->fail('Expected XmlRpcFaultException was not thrown');
        } catch (XmlRpcFaultException $e) {
            $this->assertEquals(4, $e->getFaultCode());
            $this->assertEquals('Too many parameters.', $e->getFaultString());
        }
    }

    // -------------------------------------------------------
    // Error handling
    // -------------------------------------------------------

    public function test_throws_exception_when_endpoint_empty(): void
    {
        $this->expectException(XmlRpcException::class);
        $this->expectExceptionMessage('not configured');

        $client = new XmlRpcClient('');
        $client->call('test.method');
    }

    public function test_throws_exception_on_http_failure(): void
    {
        Http::fake([
            '*' => Http::response('Internal Server Error', 500),
        ]);

        $this->expectException(XmlRpcException::class);
        $this->expectExceptionMessage('HTTP request failed');

        $this->makeClient()->call('test.method');
    }

    public function test_throws_exception_on_invalid_xml_response(): void
    {
        Http::fake([
            '*' => Http::response('this is not xml', 200),
        ]);

        $this->expectException(XmlRpcException::class);
        $this->expectExceptionMessage('Failed to parse');

        $this->makeClient()->call('test.method');
    }

    // -------------------------------------------------------
    // Authentication
    // -------------------------------------------------------

    public function test_sends_basic_auth_when_credentials_provided(): void
    {
        $this->fakeXmlRpcResponse($this->simpleStringResponse('OK'));
        $client = $this->makeClient('https://example.com/xmlrpc.php', 'user', 'secret');

        $client->call('test.method');

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization');
            $this->assertNotEmpty($auth);
            $this->assertStringStartsWith('Basic ', $auth[0]);
            $decoded = base64_decode(substr($auth[0], 6));
            $this->assertEquals('user:secret', $decoded);
            return true;
        });
    }

    public function test_no_auth_header_without_credentials(): void
    {
        $this->fakeXmlRpcResponse($this->simpleStringResponse('OK'));
        $client = $this->makeClient();

        $client->call('test.method');

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization');
            $this->assertEmpty($auth);
            return true;
        });
    }

    // -------------------------------------------------------
    // callRaw
    // -------------------------------------------------------

    public function test_call_raw_returns_dom_document(): void
    {
        $this->fakeXmlRpcResponse($this->simpleStringResponse('OK'));
        $client = $this->makeClient();

        $result = $client->callRaw('test.method');

        $this->assertInstanceOf(DOMDocument::class, $result);
        $this->assertStringContainsString('OK', $result->saveXML());
    }

    // -------------------------------------------------------
    // Service provider
    // -------------------------------------------------------

    public function test_service_provider_registers_singleton(): void
    {
        $this->app['config']->set('xmlrpc-client.endpoint', 'https://test.com/rpc');
        $this->app['config']->set('xmlrpc-client.username', 'testuser');
        $this->app['config']->set('xmlrpc-client.password', 'testpass');

        $client = $this->app->make(XmlRpcClient::class);

        $this->assertInstanceOf(XmlRpcClient::class, $client);
        $this->assertEquals('https://test.com/rpc', $client->getEndpoint());
    }

    public function test_service_provider_resolves_same_instance(): void
    {
        $client1 = $this->app->make(XmlRpcClient::class);
        $client2 = $this->app->make(XmlRpcClient::class);

        $this->assertSame($client1, $client2);
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    protected function simpleStringResponse(string $value): string
    {
        return '<?xml version="1.0"?>
        <methodResponse>
            <params><param><value><string>' . htmlspecialchars($value) . '</string></value></param></params>
        </methodResponse>';
    }
}
