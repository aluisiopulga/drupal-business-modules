<?php

declare(strict_types=1);

namespace Drupal\Tests\acknowledgement_notices\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests sending, acknowledging and editing notices.
 *
 * @group acknowledgement_notices
 */
#[Group('acknowledgement_notices')]
#[RunTestsInSeparateProcesses]
class NoticeRepositoryTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'acknowledgement_notices'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('acknowledgement_notices', ['ack_notice', 'ack_notice_recipient']);
  }

  /**
   * The repository under test.
   */
  protected function repository() {
    return $this->container->get('acknowledgement_notices.repository');
  }

  /**
   * Sending to everyone reaches every active user except the author.
   */
  public function testSendToEveryone(): void {
    $author = $this->createUser();
    $alice = $this->createUser();
    $bob = $this->createUser();
    $blocked = $this->createUser([], NULL, FALSE, ['status' => 0]);

    $id = $this->repository()->send((int) $author->id(), 'Close of March', "Please review.\n\nThanks.", NULL);
    $recipients = array_keys($this->repository()->recipients($id));
    sort($recipients);

    $this->assertSame([(int) $alice->id(), (int) $bob->id()], $recipients);
    $this->assertNotContains((int) $blocked->id(), $recipients);

    $late = $this->createUser();
    $this->assertSame([], $this->repository()->pending((int) $late->id()), 'People who join later do not inherit old notices.');
  }

  /**
   * Pending notices come oldest first and disappear once acknowledged.
   */
  public function testPendingAndAcknowledge(): void {
    $author = $this->createUser();
    $alice = $this->createUser();
    $first = $this->repository()->send((int) $author->id(), 'First', 'One', [(int) $alice->id()]);
    $second = $this->repository()->send((int) $author->id(), 'Second', 'Two', [(int) $alice->id()]);

    $this->assertSame([$first, $second], $this->repository()->pending((int) $alice->id()));
    $this->assertTrue($this->repository()->acknowledge($first, (int) $alice->id()));
    $this->assertFalse($this->repository()->acknowledge($first, (int) $alice->id()), 'Acknowledging twice keeps the first date.');
    $this->assertSame([$second], $this->repository()->pending((int) $alice->id()));
    $this->assertGreaterThan(0, $this->repository()->recipients($first)[(int) $alice->id()]);
  }

  /**
   * A notice is editable only until the first acknowledgement.
   */
  public function testEditableUntilAcknowledged(): void {
    $author = $this->createUser();
    $alice = $this->createUser();
    $id = $this->repository()->send((int) $author->id(), 'Draft', 'Text', [(int) $alice->id()]);

    $this->assertTrue($this->repository()->isEditable($id));
    $this->repository()->update($id, 'Final', 'Better text');
    $this->assertSame('Final', $this->repository()->load($id)->title);

    $this->repository()->acknowledge($id, (int) $alice->id());
    $this->assertFalse($this->repository()->isEditable($id));

    $this->expectException(\LogicException::class);
    $this->repository()->update($id, 'Sneaky change', 'Changed after reading');
  }

  /**
   * Deleted notices stop being pending but stay in the database.
   */
  public function testDelete(): void {
    $author = $this->createUser();
    $alice = $this->createUser();
    $id = $this->repository()->send((int) $author->id(), 'Oops', 'Wrong text', [(int) $alice->id()]);

    $this->repository()->delete($id);
    $this->assertSame([], $this->repository()->pending((int) $alice->id()));
    $this->assertGreaterThan(0, (int) $this->repository()->load($id)->deleted);
  }

  /**
   * Counts in the sent list.
   */
  public function testSentCounts(): void {
    $author = $this->createUser();
    $alice = $this->createUser();
    $bob = $this->createUser();
    $id = $this->repository()->send((int) $author->id(), 'Team', 'Hello', [(int) $alice->id(), (int) $bob->id()]);
    $this->repository()->acknowledge($id, (int) $alice->id());

    $sent = $this->repository()->sent((int) $author->id());
    $this->assertCount(1, $sent);
    $this->assertSame(2, (int) $sent[0]->recipient_count);
    $this->assertSame(1, (int) $sent[0]->acknowledged_count);
  }

}
