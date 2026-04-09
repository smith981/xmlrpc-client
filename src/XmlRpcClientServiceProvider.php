<?php

namespace Hardtail\XmlRpcClient;

use Illuminate\Support\ServiceProvider;

class XmlRpcClientServiceProvider extends ServiceProvider
{
  public function register(): void
  {
    $this->mergeConfigFrom(__DIR__ . '/../config/xmlrpc-client.php', 'xmlrpc-client');

    // Bind the main client class
    $this->app->singleton(XmlRpcClient::class, function ($app) {
      $config = $app['config']['xmlrpc-client'];
      return new XmlRpcClient(
        $config['endpoint'],
        $config['username'] ?? null,
        $config['password'] ?? null
      );
    });
  }

  public function boot(): void
  {
    if ($this->app->runningInConsole()) {
      $this->publishes([
        __DIR__ . '/../config/xmlrpc-client.php' => config_path('xmlrpc-client.php'),
      ], 'xmlrpc-client-config');
    }
  }
}