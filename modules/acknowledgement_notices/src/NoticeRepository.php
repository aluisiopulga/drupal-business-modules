<?php

declare(strict_types=1);

namespace Drupal\acknowledgement_notices;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Stores notices, their recipients and acknowledgements.
 */
class NoticeRepository {

  /**
   * Routes where the overlay never appears.
   *
   * The acknowledge form itself, and logging in or out: blocking the logout
   * would trap people on the site.
   */
  public const FREE_ROUTES = [
    'acknowledgement_notices.acknowledge',
    'user.login',
    'user.logout',
    'user.logout.confirm',
    'user.pass',
    'user.reset',
    'user.reset.form',
    'user.reset.login',
  ];

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Sends a notice.
   *
   * @param int $author
   *   The author's user ID.
   * @param string $title
   *   Plain-text title.
   * @param string $message
   *   Plain-text message.
   * @param int[]|null $recipients
   *   User IDs, or NULL for every active user. The author never receives
   *   their own notice.
   *
   * @return int
   *   The new notice ID.
   */
  public function send(int $author, string $title, string $message, ?array $recipients): int {
    $transaction = $this->database->startTransaction();

    $id = (int) $this->database->insert('ack_notice')->fields([
      'uid' => $author,
      'title' => mb_substr(trim($title), 0, 255),
      'message' => trim($message),
      'everyone' => $recipients === NULL ? 1 : 0,
      'created' => $this->time->getCurrentTime(),
    ])->execute();

    $uids = $recipients ?? $this->activeUserIds();
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static fn (int $uid) => $uid > 0 && $uid !== $author)));
    if ($uids) {
      $insert = $this->database->insert('ack_notice_recipient')->fields(['notice_id', 'uid', 'acknowledged']);
      foreach ($uids as $uid) {
        $insert->values([$id, $uid, 0]);
      }
      $insert->execute();
    }

    unset($transaction);

    return $id;
  }

  /**
   * Loads one notice, deleted or not.
   */
  public function load(int $id): ?object {
    $notice = $this->database->select('ack_notice', 'n')->fields('n')->condition('n.id', $id)->execute()->fetchObject();

    return $notice ?: NULL;
  }

  /**
   * Active notices the user still has to acknowledge, oldest first.
   *
   * @return int[]
   *   Notice IDs.
   */
  public function pending(int $uid): array {
    $query = $this->database->select('ack_notice_recipient', 'r');
    $query->join('ack_notice', 'n', 'n.id = r.notice_id');

    return array_map('intval', $query->fields('r', ['notice_id'])
      ->condition('r.uid', $uid)
      ->condition('r.acknowledged', 0)
      ->condition('n.deleted', 0)
      ->orderBy('n.created')
      ->orderBy('n.id')
      ->execute()
      ->fetchCol());
  }

  /**
   * Whether the user received the notice and has not acknowledged it yet.
   */
  public function isPendingFor(int $id, int $uid): bool {
    return in_array($id, $this->pending($uid), TRUE);
  }

  /**
   * Records the acknowledgement. Acknowledging twice keeps the first date.
   */
  public function acknowledge(int $id, int $uid): bool {
    return (bool) $this->database->update('ack_notice_recipient')
      ->fields(['acknowledged' => $this->time->getCurrentTime()])
      ->condition('notice_id', $id)
      ->condition('uid', $uid)
      ->condition('acknowledged', 0)
      ->execute();
  }

  /**
   * Recipients of a notice and when they acknowledged it.
   *
   * @return array<int, int>
   *   User ID => acknowledgement timestamp (0 while pending).
   */
  public function recipients(int $id): array {
    return array_map('intval', $this->database->select('ack_notice_recipient', 'r')
      ->fields('r', ['uid', 'acknowledged'])
      ->condition('r.notice_id', $id)
      ->orderBy('r.uid')
      ->execute()
      ->fetchAllKeyed());
  }

  /**
   * Notices received by a user, newest first.
   *
   * @return object[]
   *   Notice rows with an extra "acknowledged" property.
   */
  public function received(int $uid): array {
    $query = $this->database->select('ack_notice', 'n');
    $query->join('ack_notice_recipient', 'r', 'r.notice_id = n.id');

    return $query->fields('n')
      ->fields('r', ['acknowledged'])
      ->condition('r.uid', $uid)
      ->condition('n.deleted', 0)
      ->orderBy('n.created', 'DESC')
      ->execute()
      ->fetchAll();
  }

  /**
   * Notices sent by a user (or by anyone when $uid is NULL), newest first.
   *
   * @return object[]
   *   Notice rows with "recipient_count" and "acknowledged_count".
   */
  public function sent(?int $uid): array {
    $query = $this->database->select('ack_notice', 'n');
    $query->leftJoin('ack_notice_recipient', 'r', 'r.notice_id = n.id');
    $query->fields('n');
    $query->addExpression('COUNT(r.uid)', 'recipient_count');
    $query->addExpression('SUM(CASE WHEN r.acknowledged > 0 THEN 1 ELSE 0 END)', 'acknowledged_count');
    $query->condition('n.deleted', 0)->groupBy('n.id')->orderBy('n.created', 'DESC');
    if ($uid !== NULL) {
      $query->condition('n.uid', $uid);
    }
    // Group by every selected column for databases in strict mode.
    foreach (['uid', 'title', 'message', 'everyone', 'created', 'changed', 'deleted'] as $column) {
      $query->groupBy('n.' . $column);
    }

    return $query->execute()->fetchAll();
  }

  /**
   * A notice can be edited or deleted only until someone acknowledges it.
   *
   * After that, changing it would change what people declared they read.
   */
  public function isEditable(int $id): bool {
    $notice = $this->load($id);
    if ($notice === NULL || (int) $notice->deleted > 0) {
      return FALSE;
    }

    return !$this->database->select('ack_notice_recipient', 'r')
      ->condition('r.notice_id', $id)
      ->condition('r.acknowledged', 0, '>')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Changes the title and message of an editable notice.
   */
  public function update(int $id, string $title, string $message): void {
    if (!$this->isEditable($id)) {
      throw new \LogicException("Notice $id was already acknowledged and can no longer be edited.");
    }
    $this->database->update('ack_notice')
      ->fields([
        'title' => mb_substr(trim($title), 0, 255),
        'message' => trim($message),
        'changed' => $this->time->getCurrentTime(),
      ])
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Deletes an editable notice. The row is kept, only hidden.
   */
  public function delete(int $id): void {
    if (!$this->isEditable($id)) {
      throw new \LogicException("Notice $id was already acknowledged and can no longer be deleted.");
    }
    $this->database->update('ack_notice')
      ->fields(['deleted' => $this->time->getCurrentTime()])
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Removes everything about a user (called when the account is deleted).
   */
  public function forgetUser(int $uid): void {
    $this->database->delete('ack_notice_recipient')->condition('uid', $uid)->execute();
  }

  /**
   * IDs of every active, non-anonymous user.
   *
   * @return int[]
   *   User IDs.
   */
  protected function activeUserIds(): array {
    return array_map('intval', $this->database->select('users_field_data', 'u')
      ->fields('u', ['uid'])
      ->condition('u.status', 1)
      ->condition('u.uid', 0, '>')
      ->distinct()
      ->execute()
      ->fetchCol());
  }

}
