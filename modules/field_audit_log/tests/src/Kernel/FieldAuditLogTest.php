<?php

declare(strict_types=1);

namespace Drupal\Tests\field_audit_log\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that changes to tracked content are recorded.
 *
 * @group field_audit_log
 */
#[Group('field_audit_log')]
#[RunTestsInSeparateProcesses]
class FieldAuditLogTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'text', 'filter', 'node', 'field_audit_log'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('field_audit_log', ['field_audit_log']);
    $this->installConfig(['filter', 'node', 'field_audit_log']);

    NodeType::create(['type' => 'balance', 'name' => 'Balance'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->config('field_audit_log.settings')->set('tracked', ['node:balance'])->save();

    $this->createUser();
    $this->setCurrentUser($this->createUser(['access content']));
  }

  /**
   * Create, update and delete each leave one entry with the right values.
   */
  public function testLifecycle(): void {
    $logger = $this->container->get('field_audit_log.logger');
    $node = Node::create(['type' => 'balance', 'title' => 'March']);
    $node->save();

    $entries = $logger->entries(['entity_type' => 'node', 'entity_id' => (string) $node->id()]);
    $this->assertCount(1, $entries);
    $this->assertSame('create', $entries[0]->action);
    $this->assertSame([NULL, 'March'], $entries[0]->changes['title']);
    $this->assertSame('cli', $entries[0]->path);
    $this->assertSame((int) $this->container->get('current_user')->id(), (int) $entries[0]->uid);

    $node->setTitle('March (corrected)')->save();
    $entries = $logger->entries(['entity_id' => (string) $node->id()]);
    $this->assertCount(2, $entries);
    $this->assertSame('update', $entries[0]->action);
    $this->assertSame(['title' => ['March', 'March (corrected)']], $entries[0]->changes);

    $id = (string) $node->id();
    $node->delete();
    $entries = $logger->entries(['entity_id' => $id]);
    $this->assertCount(3, $entries, 'Entries outlive the deleted entity.');
    $this->assertSame('delete', $entries[0]->action);
    $this->assertSame(['March (corrected)', NULL], $entries[0]->changes['title']);
  }

  /**
   * Saving without changing anything is not recorded.
   */
  public function testNoOpSaveIsIgnored(): void {
    $node = Node::create(['type' => 'balance', 'title' => 'April']);
    $node->save();
    $node->save();

    $this->assertCount(1, $this->container->get('field_audit_log.logger')->entries(['entity_id' => (string) $node->id()]));
  }

  /**
   * Untracked bundles are ignored.
   */
  public function testUntrackedBundle(): void {
    Node::create(['type' => 'page', 'title' => 'About'])->save();

    $this->assertSame([], $this->container->get('field_audit_log.logger')->entries());
  }

  /**
   * Old entries are purged when a retention is set.
   */
  public function testRetention(): void {
    $database = $this->container->get('database');
    $node = Node::create(['type' => 'balance', 'title' => 'May']);
    $node->save();
    $database->update('field_audit_log')->fields(['timestamp' => strtotime('-40 days')])->execute();

    $logger = $this->container->get('field_audit_log.logger');
    $this->assertSame(0, $logger->purge(), 'No retention by default.');

    $this->config('field_audit_log.settings')->set('retention_days', 30)->save();
    $this->assertSame(1, $logger->purge());
    $this->assertSame([], $logger->entries());
  }

  /**
   * The diff ignores fields that stay empty.
   */
  public function testDiff(): void {
    $logger = $this->container->get('field_audit_log.logger');
    $this->assertSame(['a' => [NULL, '1']], $logger->diff(NULL, ['a' => '1', 'b' => '']));
    $this->assertSame(['a' => ['1', '2']], $logger->diff(['a' => '1', 'b' => 'x'], ['a' => '2', 'b' => 'x']));
    $this->assertSame([], $logger->diff(['a' => '1'], ['a' => '1']));
  }

}
