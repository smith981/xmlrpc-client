# hardtail/xmlrpc-client

An XML-RPC client for Laravel 12. Sends XML-RPC requests over HTTP and parses responses into native PHP types.

## Installation

```bash
composer require hardtail/xmlrpc-client
```

The service provider is auto-discovered. Publish the config if you need to customize it:

```bash
php artisan vendor:publish --tag=xmlrpc-client-config
```

## Configuration

Set these in your `.env`:

```
XMLRPC_ENDPOINT=https://your-server.com/xmlrpc.php
XMLRPC_USERNAME=
XMLRPC_PASSWORD=
```

## Usage

### Via dependency injection

```php
use Hardtail\XmlRpcClient\XmlRpcClient;

class MyController extends Controller
{
    public function index(XmlRpcClient $client)
    {
        $result = $client->call('system.listMethods');

        // Pass parameters as additional arguments
        $result = $client->call('getUser', 'john_doe');

        // Multiple params, mixed types
        $result = $client->call('search', 'query', 10, true);
    }
}
```

### Via the container

```php
$client = app(XmlRpcClient::class);
$result = $client->call('myMethod', 'param1', 'param2');
```

### Standalone (without service provider)

```php
$client = new XmlRpcClient('https://example.com/xmlrpc.php', 'user', 'pass');
$result = $client->call('myMethod', 'arg1');
```

### Raw DOMDocument response

If you need the unparsed XML response:

```php
$dom = $client->callRaw('myMethod', 'arg1');
```

## Supported types

| PHP type               | XML-RPC type |
|------------------------|-------------|
| `string`               | `<string>`  |
| `int`                  | `<int>`     |
| `float`                | `<double>`  |
| `bool`                 | `<boolean>` |
| `null`                 | `<nil>`     |
| sequential `array`     | `<array>`   |
| associative `array`    | `<struct>`  |

## Artisan command

The package includes an `xmlrpc:call` command for executing XML-RPC requests from the CLI:

```bash
# Call a method with no arguments
php artisan xmlrpc:call system.listMethods

# Pass arguments after the method name
php artisan xmlrpc:call getUser john_doe

# Multiple arguments
php artisan xmlrpc:call search query 10

# Output raw XML instead of colorized output
php artisan xmlrpc:call system.listMethods --raw

# Override the configured endpoint for a single call
php artisan xmlrpc:call system.listMethods --endpoint=https://other-server.com/xmlrpc.php
```

By default, the response is colorized for readability — XML tags in yellow, struct keys in cyan, string values in green, and fault responses in red.

## Error handling

```php
use Hardtail\XmlRpcClient\Exceptions\XmlRpcException;
use Hardtail\XmlRpcClient\Exceptions\XmlRpcFaultException;

try {
    $result = $client->call('myMethod');
} catch (XmlRpcFaultException $e) {
    // XML-RPC fault returned by the server
    $e->getFaultCode();
    $e->getFaultString();
} catch (XmlRpcException $e) {
    // HTTP error, bad XML, missing config, etc.
    $e->getMessage();
}
```

## Testing

```bash
composer test
```

## License

MIT
