<?php

namespace Ryank90\LumenSendGrid;

use GuzzleHttp\Client as HttpClient;
use Illuminate\Mail\TransportManager;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;

use Ryank90\LumenSendGrid\SendGridTransport\SendGridTransport;

class SendGridServiceProvider extends ServiceProvider
{
  public function register()
  {
    $this->app->afterResolving(TransportManager::class, function(TransportManager $manager) {
      $this->extendTransportManager($manager);
    });
  }

  public function extendTransportManager(TransportManager $manager)
  {
    $manager->extend('sendgrid', function() {
      $config = $this->app['config']->get('services.sendgrid', array());
      $client = new HttpClient(Arr::get($config, 'guzzle', []));

      return new SendgridTransport($client, $config['api_key']);
    });
  }
}
