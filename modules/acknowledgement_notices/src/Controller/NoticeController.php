<?php

declare(strict_types=1);

namespace Drupal\acknowledgement_notices\Controller;

use Drupal\acknowledgement_notices\NoticeRepository;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Notice pages and their access rules.
 */
class NoticeController extends ControllerBase {

  public function __construct(
    protected NoticeRepository $repository,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('acknowledgement_notices.repository'),
      $container->get('date.formatter'),
    );
  }

  /**
   * The current user's notices: received, and sent (or all, for admins).
   */
  public function list(): array {
    $uid = (int) $this->currentUser()->id();
    $is_admin = $this->currentUser()->hasPermission('administer acknowledgement notices');

    $build['#cache'] = ['max-age' => 0];

    if ($this->currentUser()->hasPermission('send acknowledgement notices')) {
      $build['add'] = [
        '#type' => 'link',
        '#title' => $this->t('Send a notice'),
        '#url' => Url::fromRoute('acknowledgement_notices.add'),
        '#attributes' => ['class' => ['button', 'button--primary', 'button-action']],
      ];
    }

    $build['received'] = [
      '#type' => 'table',
      '#caption' => $this->t('Received'),
      '#header' => [$this->t('Title'), $this->t('Sent'), $this->t('Acknowledged')],
      '#empty' => $this->t('You have not received any notice.'),
      '#rows' => array_map(fn (object $n): array => [
        $this->link($n),
        $this->dateFormatter->format((int) $n->created, 'short'),
        $n->acknowledged ? $this->dateFormatter->format((int) $n->acknowledged, 'short') : $this->t('Pending'),
      ], $this->repository->received($uid)),
    ];

    if ($is_admin || $this->currentUser()->hasPermission('send acknowledgement notices')) {
      $build['sent'] = [
        '#type' => 'table',
        '#caption' => $is_admin ? $this->t('All notices') : $this->t('Sent by you'),
        '#header' => [$this->t('Title'), $this->t('Sent'), $this->t('Acknowledged')],
        '#empty' => $this->t('No notices sent yet.'),
        '#rows' => array_map(fn (object $n): array => [
          $this->link($n),
          $this->dateFormatter->format((int) $n->created, 'short'),
          $this->t('@done of @total', ['@done' => (int) $n->acknowledged_count, '@total' => (int) $n->recipient_count]),
        ], $this->repository->sent($is_admin ? NULL : $uid)),
      ];
    }

    return $build;
  }

  /**
   * One notice, with the acknowledgement list for its author and admins.
   */
  public function view(int $notice): array {
    $row = $this->repository->load($notice);
    $build['#cache'] = ['max-age' => 0];
    $build['#title'] = $row->title;
    $build['message'] = ['#markup' => Markup::create(acknowledgement_notices_text_to_html($row->message))];

    if ($this->canManage($row, $this->currentUser())) {
      $users = $this->entityTypeManager()->getStorage('user')->loadMultiple(array_keys($this->repository->recipients($notice)));
      $rows = [];
      foreach ($this->repository->recipients($notice) as $uid => $acknowledged) {
        $rows[] = [
          isset($users[$uid]) ? $users[$uid]->getDisplayName() : $this->t('Deleted user @uid', ['@uid' => $uid]),
          $acknowledged ? $this->dateFormatter->format($acknowledged, 'short') : $this->t('Pending'),
        ];
      }
      $build['recipients'] = [
        '#type' => 'table',
        '#caption' => $this->t('Recipients'),
        '#header' => [$this->t('User'), $this->t('Acknowledged')],
        '#rows' => $rows,
        '#empty' => $this->t('Nobody received this notice.'),
      ];

      if ($this->repository->isEditable($notice)) {
        $build['actions'] = [
          '#theme' => 'item_list',
          '#items' => [
            [
              '#type' => 'link',
              '#title' => $this->t('Edit'),
              '#url' => Url::fromRoute('acknowledgement_notices.edit', ['notice' => $notice]),
            ],
            [
              '#type' => 'link',
              '#title' => $this->t('Delete'),
              '#url' => Url::fromRoute('acknowledgement_notices.delete', ['notice' => $notice]),
            ],
          ],
        ];
      }
    }

    return $build;
  }

  /**
   * Recipients, the author and admins may view a notice.
   */
  public function accessView(int $notice, AccountInterface $account): AccessResultInterface {
    $row = $this->repository->load($notice);
    $allowed = $row !== NULL && (int) $row->deleted === 0
      && ($this->canManage($row, $account) || array_key_exists((int) $account->id(), $this->repository->recipients($notice)));

    return AccessResult::allowedIf($allowed)->setCacheMaxAge(0);
  }

  /**
   * The author and admins may edit or delete, until the first acknowledgement.
   */
  public function accessManage(int $notice, AccountInterface $account): AccessResultInterface {
    $row = $this->repository->load($notice);

    return AccessResult::allowedIf($row !== NULL && $this->canManage($row, $account) && $this->repository->isEditable($notice))
      ->setCacheMaxAge(0);
  }

  /**
   * Only a recipient with the notice still pending may acknowledge it.
   */
  public function accessAcknowledge(int $notice, AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf($this->repository->isPendingFor($notice, (int) $account->id()))->setCacheMaxAge(0);
  }

  /**
   * Whether the account authored the notice or administers notices.
   */
  protected function canManage(object $notice, AccountInterface $account): bool {
    return $account->hasPermission('administer acknowledgement notices')
      || ((int) $notice->uid === (int) $account->id() && $account->hasPermission('send acknowledgement notices'));
  }

  /**
   * A link to a notice.
   */
  protected function link(object $notice): array {
    return [
      'data' => [
        '#type' => 'link',
        '#title' => $notice->title,
        '#url' => Url::fromRoute('acknowledgement_notices.view', ['notice' => $notice->id]),
      ],
    ];
  }

}
