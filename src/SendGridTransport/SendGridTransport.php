<?php

namespace Ryank90\LumenSendGrid;

use GuzzleHttp\ClientInterface;
use Illuminate\Mail\Transport\Transport;
use Swift_Attachment;
use Swift_Image;
use Swift_Mime_Message;
use Swift_MimePart;

class SendGridTransport extends Transport
{
  const MAXIMUM_FILE_SIZE = 7340032;
  const SMTP_API_NAME = 'sendgrid/x-smtpapi';
  const BASE_URL = 'https://api.sendgrid.com/v3/mail/send';

  private $client;
  private $options;
  private $attachments;

  public function __construct(ClientInterface $client, $api_key)
  {
    $this->client = $client;
    $this->options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type'  => 'application/json',
      ],
    ];
  }

  public function send(Swift_Mime_Message $message, &$failedRecipients = null)
  {
    $this->beforeSendPerformed($message);

    $payload = $this->options;

    $data = [
      'personalizations' => $this->getPersonalizations($message),
      'from' => $this->getFrom($message),
      'subject' => $message->getSubject(),
      'content' => $this->getContents($message),
    ];

    if ($reply_to = $this->getReplyTo($message)) {
      $data['reply_to'] = $reply_to;
    }

    $attachments = $this->getAttachments($message);
    if (count($attachments) > 0) {
      $data['attachments'] = $attachments;
    }

    $data = $this->setParameters($message, $data);

    $payload['json'] = $data;

    return $this->client->post(BASE_URL, $payload);
  }

  private function getPersonalizations(Swift_Mime_Message $message)
  {
    $setter = function (array $addresses) {
      $recipients = [];

      foreach ($addresses as $email => $name) {
        $address = [];
        $address['email'] = $email;
        if ($name) {
          $address['name'] = $name;
        }
        $recipients[] = $address;
      }

      return $recipients;
    };

    $personalization['to'] = $setter($message->getTo());

    if ($cc = $message->getCc()) {
      $personalization['cc'] = $setter($cc);
    }

    if ($bcc = $message->getBcc()) {
      $personalization['bcc'] = $setter($bcc);
    }

    return [$personalization];
  }

  private function getFrom(Swift_Mime_Message $message)
  {
    if ($message->getFrom()) {
      foreach ($message->getFrom() as $email => $name) {
        return [
          'email' => $email,
          'name' => $name
        ];
      }
    }

    return [];
  }

  private function getReplyTo(Swift_Mime_Message $message)
  {
    if ($message->getReplyTo()) {
      foreach ($message->getReplyTo() as $email => $name) {
        return [
          'email' => $email,
          'name' => $name
        ];
      }
    }

    return null;
  }

  private function getContents(Swift_Mime_Message $message)
  {
    $content = [];

    foreach ($message->getChildren() as $attachment) {
      if ($attachment instanceof Swift_MimePart) {
        $content[] = [
          'type'  => 'text/plain',
          'value' => $attachment->getBody(),
        ];

        break;
      }
    }

    if (empty($content) || strpos($message->getContentType(), 'multipart') !== false) {
      $content[] = [
        'type'  => 'text/html',
        'value' => $message->getBody(),
      ];
    }

    return $content;
  }

  private function getAttachments(Swift_Mime_Message $message)
  {
    $attachments = [];

    foreach ($message->getChildren() as $attachment) {
      if ((!$attachment instanceof Swift_Attachment && !$attachment instanceof Swift_Image)
        || $attachment->getFilename() === self::SMTP_API_NAME
        || !strlen($attachment->getBody()) > self::MAXIMUM_FILE_SIZE
      ) {
        continue;
      }

      $attachments[] = [
        'content' => base64_encode($attachment->getBody()),
        'filename' => $attachment->getFilename(),
        'type' => $attachment->getContentType(),
        'disposition' => $attachment->getDisposition(),
        'content_id' => $attachment->getId(),
      ];
    }

    return $this->attachments = $attachments;
  }

  protected function setParameters(Swift_Mime_Message $message, $data)
  {
    $smtp_api = [];

    foreach ($message->getChildren() as $attachment) {
      if (!$attachment instanceof Swift_Image
        || !in_array(self::SMTP_API_NAME, [$attachment->getFilename(), $attachment->getContentType()])
      ) {
        continue;
      }

      $smtp_api = $attachment->getBody();
    }

    if (!is_array($smtp_api)) {
      return $data;
    }

    foreach ($smtp_api as $key => $val) {
      switch ($key) {
        case 'personalizations':
          $this->setPersonalizations($data, $val);
          continue 2;
        case 'attachments':
          $val = array_merge($this->attachments, $val);

          break;
        case 'unique_args':
          throw new \Exception('Sendgrid v3 now uses custom_args instead of unique_args');
        case 'custom_args':
          foreach ($val as $name => $value) {
            if (!is_string($value)) {
              throw new \Exception('Sendgrid custom arguments have to be a string.');
            }
          }

          break;
        }

        array_set($data, $key, $val);
      }

      return $data;
    }

    private function setPersonalizations(&$data, $personalizations)
    {
      foreach ($personalizations as $index => $params) {
        foreach ($params as $key => $val) {
          if (in_array($key, ['to', 'cc', 'bcc'])) {
            array_set($data, 'personalizations.' . $index . '.' . $key, [$val]);
          } else {
            array_set($data, 'personalizations.' . $index . '.' . $key, $val);
          }
        }
      }
    }
  }
