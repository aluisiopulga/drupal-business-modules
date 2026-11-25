<?php

declare(strict_types=1);

namespace Drupal\period_lock\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\period_lock\PeriodLockChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the PeriodLock constraint.
 *
 * Access control (hook_entity_access) already hides the edit and delete
 * operations of locked content. This validator is the server-side guarantee
 * for the cases access cannot see: the date typed into a new record, and a
 * date moved into a closed period while editing.
 */
class PeriodLockConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected PeriodLockChecker $checker,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('period_lock.checker'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $entity, Constraint $constraint): void {
    /** @var \Drupal\period_lock\Plugin\Validation\Constraint\PeriodLockConstraint $constraint */
    $closed_through = $this->checker->closedThrough();
    if ($closed_through === NULL || !$this->checker->fieldsFor($entity) || $this->checker->canBypass($this->currentUser)) {
      return;
    }

    $arguments = ['%date' => $closed_through];

    if ($entity->isNew()) {
      if ($this->checker->isInClosedPeriod($entity)) {
        $this->context->addViolation($constraint->createMessage, $arguments);
      }
      return;
    }

    $original = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    if ($original !== NULL && $this->checker->isLocked($original)) {
      $this->context->addViolation($constraint->lockedMessage, $arguments);
      return;
    }

    if ($this->checker->isInClosedPeriod($entity) && ($original === NULL || !$this->checker->isInClosedPeriod($original))) {
      $this->context->addViolation($constraint->moveMessage, $arguments);
    }
  }

}
