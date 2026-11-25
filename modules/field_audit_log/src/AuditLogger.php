<?php

declare(strict_types=1);

namespace Drupal\field_audit_log;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Writes and reads the audit trail.
 */
class AuditLogger {

  /**
   * Fields that change on every save and say nothing about the content.
   */
  protected const IGNORED_FIELDS = [
    'changed',
    'revision_timestamp',
    'revision_created',
    'revision_uid',
    'revision_user',
    'revision_log',
    'revision_log_message',
    'revision_default',
    'revision_translation_affected',
    'default_langcode',
  ];

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected AccountInterface $currentUser,
    protected RequestStack $requestStack,
    protected TimeInterface $time,
  ) {}

  /**
   * Whether this entity's bundle is tracked.
   */
  public function isTracked(EntityInterface $entity): bool {
    return $entity instanceof ContentEntityInterface
      && in_array($entity->getEntityTypeId() . ':' . $entity->bundle(), (array) $this->configFactory->get('field_audit_log.settings')->get('tracked'), TRUE);
  }

  /**
   * The comparable state of an entity: field name => value as text.
   *
   * @return array<string, string>
   *   Stored fields only (computed fields and bookkeeping fields are skipped).
   *   Multi-value fields are joined with ", ".
   */
  public function snapshot(ContentEntityInterface $entity): array {
    $keys = $entity->getEntityType()->getKeys();
    $entity_keys = array_filter([$keys['id'] ?? NULL, $keys['uuid'] ?? NULL, $keys['revision'] ?? NULL]);
    $skip = array_merge(self::IGNORED_FIELDS, $entity_keys);

    $values = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if (in_array($name, $skip, TRUE) || $definition->isComputed()) {
        continue;
      }
      $values[$name] = $this->stringify($entity->get($name));
    }

    return $values;
  }

  /**
   * A field's main values as text, comparable before and after a save.
   *
   * Values are read from the main property of each item and normalized, so a
   * boolean FALSE in memory and the "0" loaded from the database compare equal.
   */
  protected function stringify(FieldItemListInterface $items): string {
    $main = $items->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName();
    $values = [];
    foreach ($items as $item) {
      $value = $main !== NULL ? $item->get($main)->getValue() : $item->getString();
      $values[] = match (TRUE) {
        is_bool($value) => $value ? '1' : '0',
        is_scalar($value) => (string) $value,
        default => '',
      };
    }

    return implode(', ', array_filter($values, static fn (string $v): bool => $v !== ''));
  }

  /**
   * The fields that differ between two snapshots.
   *
   * @param array<string, string>|null $before
   *   NULL for a new entity.
   * @param array<string, string>|null $after
   *   NULL for a deleted entity.
   *
   * @return array<string, array{0: ?string, 1: ?string}>
   *   field name => [before, after], empty values omitted on create and delete.
   */
  public function diff(?array $before, ?array $after): array {
    $changes = [];
    foreach (array_keys(($before ?? []) + ($after ?? [])) as $field) {
      $old = $before === NULL ? NULL : ($before[$field] ?? '');
      $new = $after === NULL ? NULL : ($after[$field] ?? '');
      if ($old === $new || (($old ?? '') === '' && ($new ?? '') === '')) {
        continue;
      }
      $changes[$field] = [$old, $new];
    }

    return $changes;
  }

  /**
   * Records an event.
   *
   * Updates that change nothing are not recorded: Drupal sometimes saves the
   * same entity twice in one request (for example to fill a generated title),
   * and that is nobody changing anything.
   */
  public function log(ContentEntityInterface $entity, string $action, ?ContentEntityInterface $original = NULL): void {
    if (!$this->isTracked($entity)) {
      return;
    }

    $now = $this->snapshot($entity);
    $changes = match ($action) {
      'create' => $this->diff(NULL, $now),
      'update' => $this->diff($original ? $this->snapshot($original) : [], $now),
      'delete' => $this->diff($now, NULL),
      default => throw new \InvalidArgumentException("Unknown action: $action"),
    };
    if ($action === 'update' && $changes === []) {
      return;
    }

    $this->database->insert('field_audit_log')->fields([
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => (string) $entity->id(),
      'bundle' => $entity->bundle(),
      'label' => mb_substr((string) $entity->label(), 0, 255),
      'action' => $action,
      'uid' => (int) $this->currentUser->id(),
      'timestamp' => $this->time->getRequestTime(),
      'path' => $this->where(),
      'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ])->execute();
  }

  /**
   * Entries, newest first.
   *
   * @param array{entity_type?: string, entity_id?: string, uid?: int} $filters
   *   Optional filters by entity type, entity ID and user ID.
   * @param int $limit
   *   Maximum number of rows.
   * @param int $offset
   *   Rows to skip, for paging.
   *
   * @return object[]
   *   Rows with "changes" already decoded to an array.
   */
  public function entries(array $filters = [], int $limit = 50, int $offset = 0): array {
    $query = $this->database->select('field_audit_log', 'l')->fields('l');
    foreach (['entity_type', 'entity_id', 'uid'] as $key) {
      if (isset($filters[$key]) && $filters[$key] !== '') {
        $query->condition('l.' . $key, $filters[$key]);
      }
    }
    $rows = $query->orderBy('l.timestamp', 'DESC')->orderBy('l.id', 'DESC')->range($offset, $limit)->execute()->fetchAll();
    foreach ($rows as $row) {
      $row->changes = json_decode((string) $row->changes, TRUE) ?: [];
    }

    return $rows;
  }

  /**
   * Deletes entries older than the configured retention.
   *
   * @return int
   *   Number of rows deleted.
   */
  public function purge(): int {
    $days = (int) $this->configFactory->get('field_audit_log.settings')->get('retention_days');
    if ($days <= 0) {
      return 0;
    }

    return (int) $this->database->delete('field_audit_log')
      ->condition('timestamp', $this->time->getRequestTime() - $days * 86400, '<')
      ->execute();
  }

  /**
   * The page where the change happened, or "cli" outside a web request.
   */
  protected function where(): string {
    if (PHP_SAPI === 'cli') {
      return 'cli';
    }
    $request = $this->requestStack->getCurrentRequest();

    return $request ? mb_substr($request->getPathInfo(), 0, 255) : '';
  }

}
