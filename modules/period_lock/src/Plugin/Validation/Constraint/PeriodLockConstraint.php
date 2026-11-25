<?php

declare(strict_types=1);

namespace Drupal\period_lock\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Refuses saves that would touch a closed period.
 *
 * Added to every fieldable content entity type; the validator does nothing
 * for bundles that are not configured. Because it is an entity constraint, it
 * protects every path that validates entities: forms, REST and JSON:API.
 */
#[Constraint(
  id: 'PeriodLock',
  label: new TranslatableMarkup('Period lock', [], ['context' => 'Validation']),
  type: ['entity'],
)]
class PeriodLockConstraint extends SymfonyConstraint {

  /**
   * New content dated in a closed period.
   */
  public string $createMessage = 'The period up to %date is closed. Use a later date.';

  /**
   * Existing content that already belongs to a closed period.
   */
  public string $lockedMessage = 'This content belongs to a closed period (up to %date) and can no longer be changed.';

  /**
   * Existing content whose date would move into a closed period.
   */
  public string $moveMessage = 'The period up to %date is closed. The date cannot be moved into it.';

}
