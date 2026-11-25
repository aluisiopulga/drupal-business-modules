<?php

declare(strict_types=1);

namespace Drupal\period_lock;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Answers one question: does this entity belong to a closed period?
 *
 * A period is closed when its date is on or before the configured
 * "closed_through" date. An entity can have several date fields (for example
 * an issue date and a payment date); the earliest one decides, so a record
 * cannot escape the lock just because one of its dates is still open.
 */
class PeriodLockChecker {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The settings, also usable as a cacheable dependency.
   */
  public function config(): ImmutableConfig {
    return $this->configFactory->get('period_lock.settings');
  }

  /**
   * The last closed day as YYYY-MM-DD, or NULL when nothing is closed.
   */
  public function closedThrough(): ?string {
    $date = (string) $this->config()->get('closed_through');

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : NULL;
  }

  /**
   * The date fields configured for this entity's bundle.
   *
   * @return string[]
   *   Field names; empty when the bundle is not locked.
   */
  public function fieldsFor(EntityInterface $entity): array {
    foreach ((array) $this->config()->get('targets') as $target) {
      if (($target['entity_type'] ?? '') === $entity->getEntityTypeId() && ($target['bundle'] ?? '') === $entity->bundle()) {
        return array_values(array_filter((array) ($target['fields'] ?? [])));
      }
    }

    return [];
  }

  /**
   * The earliest date (YYYY-MM-DD) among the configured fields, if any.
   */
  public function earliestDate(EntityInterface $entity): ?string {
    if (!$entity instanceof FieldableEntityInterface) {
      return NULL;
    }

    $earliest = NULL;
    foreach ($this->fieldsFor($entity) as $field_name) {
      if (!$entity->hasField($field_name)) {
        continue;
      }
      foreach ($entity->get($field_name) as $item) {
        // Datetime and daterange store "Y-m-d" or "Y-m-d\TH:i:s": the first
        // ten characters are always the calendar date.
        $value = substr((string) $item->value, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && ($earliest === NULL || $value < $earliest)) {
          $earliest = $value;
        }
      }
    }

    return $earliest;
  }

  /**
   * Whether the entity's earliest date falls on or before the closing date.
   */
  public function isInClosedPeriod(EntityInterface $entity): bool {
    $closed_through = $this->closedThrough();
    $date = $this->earliestDate($entity);

    return $closed_through !== NULL && $date !== NULL && $date <= $closed_through;
  }

  /**
   * Whether an existing entity may no longer be edited or deleted.
   */
  public function isLocked(EntityInterface $entity): bool {
    return !$entity->isNew()
      && (bool) $this->config()->get('lock_existing')
      && $this->isInClosedPeriod($entity);
  }

  /**
   * Whether the account is above the lock.
   */
  public function canBypass(AccountInterface $account): bool {
    return $account->hasPermission('bypass period lock');
  }

  /**
   * Whether the account may set the closing date to $new_date.
   *
   * Moving forward closes more periods and only needs the admin permission.
   * Moving backwards (or clearing it) reopens closed periods, which is a
   * separate, more sensitive permission.
   */
  public function canSetClosedThrough(AccountInterface $account, ?string $new_date): bool {
    $current = $this->closedThrough();
    $reopens = $current !== NULL && ($new_date === NULL || $new_date < $current);

    return !$reopens || $account->hasPermission('reopen closed periods');
  }

}
