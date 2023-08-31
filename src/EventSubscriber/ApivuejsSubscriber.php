<?php

namespace Drupal\apivuejs\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Api vuejs event subscriber.
 */
class ApivuejsSubscriber implements EventSubscriberInterface {

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs event subscriber.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *        The messenger.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
  }

  /**
   * Kernel request event handler.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *        Response event.
   */
  public function onKernelRequest() {
    // $this->messenger->addStatus(__FUNCTION__);
  }

  /**
   * Kernel response event handler.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *        Response event.
   */
  public function onKernelResponse() {
    // $this->messenger->addStatus(__FUNCTION__);
  }

  /**
   *
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => [
        'onKernelRequest'
      ],
      KernelEvents::RESPONSE => [
        'onKernelResponse'
      ]
    ];
  }
}
