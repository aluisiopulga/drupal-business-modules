<?php

declare(strict_types=1);

namespace Drupal\Tests\reference_delete_guard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that referenced content cannot be deleted.
 *
 * @group reference_delete_guard
 */
#[Group('reference_delete_guard')]
#[RunTestsInSeparateProcesses]
class ReferenceDeleteGuardTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'text', 'filter', 'node', 'reference_delete_guard'];

  /**
   * A user who may delete any account and invoice.
   */
  protected UserInterface $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node', 'reference_delete_guard']);

    NodeType::create(['type' => 'account', 'name' => 'Account'])->save();
    NodeType::create(['type' => 'invoice', 'name' => 'Invoice'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_account',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_account',
      'entity_type' => 'node',
      'bundle' => 'invoice',
      'label' => 'Account',
    ])->save();

    $this->createUser();
    $this->editor = $this->createUser([
      'access content',
      'delete any account content',
      'delete any invoice content',
    ]);

    $this->config('reference_delete_guard.settings')->set('protected', ['node:account'])->save();
  }

  /**
   * A referenced account cannot be deleted until its invoices are gone.
   */
  public function testReferencedEntityIsProtected(): void {
    $account = Node::create(['type' => 'account', 'title' => 'Checking']);
    $account->save();
    $invoices = [];
    foreach ([1, 2] as $i) {
      $invoices[$i] = Node::create(['type' => 'invoice', 'title' => "Invoice $i", 'field_account' => $account->id()]);
      $invoices[$i]->save();
    }

    $counter = $this->container->get('reference_delete_guard.counter');
    $this->assertSame(['Invoice' => 2], $counter->count($account));
    $this->assertSame('2 Invoice', $counter->summarize($counter->count($account)));
    $this->assertFalse($account->access('delete', $this->editor));

    $invoices[1]->delete();
    $invoices[2]->delete();
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();

    $this->assertSame([], $counter->count($account));
    $this->assertTrue($account->access('delete', $this->editor));
  }

  /**
   * Unreferenced content and unprotected bundles are not affected.
   */
  public function testUnprotectedAndUnreferenced(): void {
    $account = Node::create(['type' => 'account', 'title' => 'Savings']);
    $account->save();
    $this->assertTrue($account->access('delete', $this->editor), 'Nothing references it.');

    $invoice = Node::create(['type' => 'invoice', 'title' => 'Invoice', 'field_account' => $account->id()]);
    $invoice->save();
    $other = Node::create(['type' => 'invoice', 'title' => 'Credit note', 'field_account' => $invoice->id()]);
    $other->save();
    $this->assertTrue($invoice->access('delete', $this->editor), 'Invoices are not a protected bundle.');
  }

  /**
   * An entity that only references itself can be deleted.
   */
  public function testSelfReferenceDoesNotBlock(): void {
    $this->config('reference_delete_guard.settings')->set('protected', ['node:invoice'])->save();
    $invoice = Node::create(['type' => 'invoice', 'title' => 'Loop']);
    $invoice->save();
    $invoice->set('field_account', $invoice->id())->save();

    $this->assertSame([], $this->container->get('reference_delete_guard.counter')->count($invoice));
  }

  /**
   * The summary reads naturally.
   */
  public function testSummarize(): void {
    $counter = $this->container->get('reference_delete_guard.counter');
    $counts = ['Invoice' => 3, 'Payment' => 1, 'User' => 2];
    $this->assertSame('3 Invoice, 1 Payment and 2 User', $counter->summarize($counts));
    $this->assertSame('', $counter->summarize([]));
  }

}
