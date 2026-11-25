<?php

declare(strict_types=1);

namespace Drupal\acknowledgement_notices\Form;

use Drupal\acknowledgement_notices\NoticeRepository;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms deleting a notice that nobody acknowledged yet.
 */
class DeleteNoticeForm extends ConfirmFormBase {

  /**
   * The notice being deleted.
   */
  protected ?object $notice = NULL;

  public function __construct(protected NoticeRepository $repository) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('acknowledgement_notices.repository'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'acknowledgement_notices_delete';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int|string|null $notice = NULL): array {
    $this->notice = $this->repository->load((int) $notice);

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete the notice %title?', ['%title' => $this->notice->title ?? '']);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('Recipients will no longer see it. Nobody has acknowledged it yet.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('acknowledgement_notices.list');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->repository->delete((int) $this->notice->id);
    $this->messenger()->addStatus($this->t('The notice was deleted.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
