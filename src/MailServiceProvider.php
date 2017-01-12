<?php

namespace Ryank90\LumenSendGrid;

class MailServiceProvider extends \Illuminate\Mail\MailServiceProvider
{
  public function register()
  {
    parent::register();

    $this->app->register(SendGridServiceProvider::class);
  }
}
