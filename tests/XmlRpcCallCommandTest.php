<?php

namespace Hardtail\XmlRpcClient\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class XmlRpcCallCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\Hardtail\XmlRpcClient\XmlRpcClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('xmlrpc-client.endpoint', 'https://example.com/xmlrpc.php');
    }

    public function test_command_displays_successful_response(): void
    {
        Http::fake([
            '*' => Http::response('<?xml version="1.0"?>
                <methodResponse>
                    <params><param><value><string>Hello World</string></value></param></params>
                </methodResponse>', 200, ['Content-Type' => 'text/xml']),
        ]);

        $this->artisan('xmlrpc:call', ['method' => 'system.listMethods'])
            ->expectsOutputToContain('Endpoint: https://example.com/xmlrpc.php')
            ->expectsOutputToContain('Method:   system.listMethods')
            ->assertSuccessful();
    }

    public function test_command_passes_arguments(): void
    {
        Http::fake([
            '*' => Http::response('<?xml version="1.0"?>
                <methodResponse>
                    <params><param><value><string>OK</string></value></param></params>
                </methodResponse>', 200, ['Content-Type' => 'text/xml']),
        ]);

        $this->artisan('xmlrpc:call', ['method' => 'test.method', 'args' => ['foo', 'bar']])
            ->expectsOutputToContain('Args:     foo, bar')
            ->assertSuccessful();

        Http::assertSent(function ($request) {
            $body = $request->body();
            return str_contains($body, '<string>foo</string>')
                && str_contains($body, '<string>bar</string>');
        });
    }

    public function test_command_shows_raw_xml_with_raw_option(): void
    {
        Http::fake([
            '*' => Http::response('<?xml version="1.0"?>
                <methodResponse>
                    <params><param><value><string>raw test</string></value></param></params>
                </methodResponse>', 200, ['Content-Type' => 'text/xml']),
        ]);

        Artisan::call('xmlrpc:call', ['method' => 'test.method', '--raw' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('<?xml version="1.0"?>', $output);
        $this->assertStringContainsString('<methodResponse>', $output);
        $this->assertStringContainsString('raw test', $output);
    }

    public function test_command_handles_fault_response(): void
    {
        Http::fake([
            '*' => Http::response('<?xml version="1.0"?>
                <methodResponse>
                    <fault><value><struct>
                        <member><name>faultCode</name><value><int>4</int></value></member>
                        <member><name>faultString</name><value><string>Too many parameters.</string></value></member>
                    </struct></value></fault>
                </methodResponse>', 200, ['Content-Type' => 'text/xml']),
        ]);

        $this->artisan('xmlrpc:call', ['method' => 'bad.method'])
            ->assertFailed();
    }

    public function test_command_handles_http_error(): void
    {
        Http::fake([
            '*' => Http::response('Internal Server Error', 500),
        ]);

        $this->artisan('xmlrpc:call', ['method' => 'test.method'])
            ->assertFailed();
    }

    public function test_command_allows_endpoint_override(): void
    {
        Http::fake([
            '*' => Http::response('<?xml version="1.0"?>
                <methodResponse>
                    <params><param><value><string>OK</string></value></param></params>
                </methodResponse>', 200, ['Content-Type' => 'text/xml']),
        ]);

        $this->artisan('xmlrpc:call', [
            'method' => 'test.method',
            '--endpoint' => 'https://other.com/rpc',
        ])
            ->expectsOutputToContain('Endpoint: https://other.com/rpc')
            ->assertSuccessful();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://other.com/rpc';
        });
    }
}
