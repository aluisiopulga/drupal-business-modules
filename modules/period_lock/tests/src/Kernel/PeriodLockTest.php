<?php

declare(strict_types=1);

namespace Drupal\Tests\period_lock\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests access and validation of content in closed periods.
 *
 * @group period_lock
 */
#[Group('period_lock')]
#[RunTestsInSeparateProcesses]
class PeriodLockTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'text', 'filter', 'datetime', 'node', 'period_lock'];

  /**
   * A user who may edit and delete any invoice.
   */
  protected UserInterface $editor;

  /**
   * A user who may also bypass the lock.
   */
  protected UserInterface $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node', 'period_lock']);

    NodeType::create(['type' => 'invoice', 'name' => 'Invoice'])->save();
    foreach (['field_issue_date', 'field_paid_date'] as $name) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => 'datetime',
        'settings' => ['datetime_type' => 'date'],
      ])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'invoice', 'label' => $name])->save();
    }

    // User 1 bypasses every check: create it first and leave it unused.
    $this->createUser();
    $permissions = [
      'access content',
      'create invoice content',
      'edit any invoice content',
      'delete any invoice content',
    ];
    $this->editor = $this->createUser($permissions);
    $this->manager = $this->createUser([...$permissions, 'bypass period lock']);

    $this->config('period_lock.settings')
      ->set('closed_through', '2025-06-30')
      ->set('lock_existing', TRUE)
      ->set('targets', [[
        'entity_type' => 'node',
        'bundle' => 'invoice',
        'fields' => ['field_issue_date', 'field_paid_date'],
      ],
      ])
      ->save();
  }

  /**
   * Builds an invoice; the caller decides whether to save it.
   */
  protected function invoice(string $issued, ?string $paid = NULL): NodeInterface {
    return Node::create([
      'type' => 'invoice',
      'title' => 'Invoice ' . $issued,
      'field_issue_date' => $issued,
      'field_paid_date' => $paid,
    ]);
  }

  /**
   * Closed content cannot be edited or deleted, except by the bypass role.
   */
  public function testAccess(): void {
    $closed = $this->invoice('2025-06-15');
    $closed->save();
    $open = $this->invoice('2025-07-01');
    $open->save();

    $this->assertFalse($closed->access('update', $this->editor));
    $this->assertFalse($closed->access('delete', $this->editor));
    $this->assertTrue($closed->access('view', $this->editor), 'Viewing is never locked.');
    $this->assertTrue($closed->access('update', $this->manager));
    $this->assertTrue($open->access('update', $this->editor));

    // Last closed day is included.
    $boundary = $this->invoice('2025-06-30');
    $boundary->save();
    $this->assertFalse($boundary->access('update', $this->editor));
  }

  /**
   * The earliest of the configured dates decides the period.
   */
  public function testEarliestDateDecides(): void {
    $invoice = $this->invoice('2025-07-10', '2025-06-20');
    $invoice->save();

    $this->assertSame('2025-06-20', $this->container->get('period_lock.checker')->earliestDate($invoice));
    $this->assertFalse($invoice->access('update', $this->editor));
  }

  /**
   * With "lock existing" off, existing content stays editable.
   */
  public function testLockExistingOff(): void {
    $this->config('period_lock.settings')->set('lock_existing', FALSE)->save();
    $closed = $this->invoice('2025-01-10');
    $closed->save();

    $this->assertTrue($closed->access('update', $this->editor));
  }

  /**
   * Nothing is locked when no closing date is set.
   */
  public function testNoClosingDate(): void {
    $this->config('period_lock.settings')->set('closed_through', '')->save();
    $invoice = $this->invoice('2020-01-01');
    $invoice->save();

    $this->assertTrue($invoice->access('update', $this->editor));
  }

  /**
   * New content dated in a closed period fails validation.
   */
  public function testCreateInClosedPeriod(): void {
    $this->setCurrentUser($this->editor);
    $violations = $this->invoice('2025-05-01')->validate();
    $this->assertCount(1, $violations->getEntityViolations());
    $this->assertStringContainsString('2025-06-30', (string) $violations->getEntityViolations()[0]->getMessage());

    $this->assertCount(0, $this->invoice('2025-07-01')->validate()->getEntityViolations());

    $this->setCurrentUser($this->manager);
    $this->assertCount(0, $this->invoice('2025-05-01')->validate()->getEntityViolations(), 'Bypass users may create in closed periods.');
  }

  /**
   * Moving an open record's date into a closed period fails validation.
   */
  public function testMoveIntoClosedPeriod(): void {
    $invoice = $this->invoice('2025-08-01');
    $invoice->save();

    $this->setCurrentUser($this->editor);
    $invoice->set('field_issue_date', '2025-03-01');
    $this->assertCount(1, $invoice->validate()->getEntityViolations());

    $invoice->set('field_issue_date', '2025-09-01');
    $this->assertCount(0, $invoice->validate()->getEntityViolations());
  }

  /**
   * Saving locked content through the API (REST, JSON:API) is refused too.
   */
  public function testEditLockedThroughValidation(): void {
    $invoice = $this->invoice('2025-02-01');
    $invoice->save();

    $this->setCurrentUser($this->editor);
    $invoice->setTitle('Changed');
    $this->assertCount(1, $invoice->validate()->getEntityViolations());
  }

  /**
   * Unconfigured bundles are never affected.
   */
  public function testOtherBundlesUntouched(): void {
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $page = Node::create(['type' => 'page', 'title' => 'About']);
    $page->save();

    $this->setCurrentUser($this->editor);
    $this->assertCount(0, $page->validate()->getEntityViolations());
  }

  /**
   * Reopening periods needs its own permission.
   */
  public function testReopenPermission(): void {
    $checker = $this->container->get('period_lock.checker');
    $admin = $this->createUser(['administer period lock']);
    $reopener = $this->createUser(['administer period lock', 'reopen closed periods']);

    $this->assertTrue($checker->canSetClosedThrough($admin, '2025-07-31'), 'Moving forward is allowed.');
    $this->assertFalse($checker->canSetClosedThrough($admin, '2025-05-31'), 'Moving backwards needs the permission.');
    $this->assertFalse($checker->canSetClosedThrough($admin, NULL), 'Clearing the date reopens everything.');
    $this->assertTrue($checker->canSetClosedThrough($reopener, '2025-05-31'));
  }

}
