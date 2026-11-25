<?php

declare(strict_types=1);

namespace Drupal\acknowledgement_notices\Form;

use Drupal\acknowledgement_notices\NoticeRepository;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends a new notice, or edits one that nobody acknowledged yet.
 */
class NoticeForm extends FormBase {

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
    return 'acknowledgement_notices_notice';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int|string|null $notice = NULL): array {
    $existing = $notice ? $this->repository->load((int) $notice) : NULL;
    $form_state->set('notice_id', $existing ? (int) $existing->id : NULL);

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#maxlength' => 255,
      '#required' => TRUE,
      '#default_value' => $existing->title ?? '',
    ];
    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#rows' => 8,
      '#required' => TRUE,
      '#default_value' => $existing->message ?? '',
      '#description' => $this->t('Plain text. Leave a blank line between paragraphs.'),
    ];

    if (!$existing) {
      $form['audience'] = [
        '#type' => 'radios',
        '#title' => $this->t('Send to'),
        '#options' => [
          'everyone' => $this->t('Everyone (all active users)'),
          'selected' => $this->t('Selected users'),
        ],
        '#default_value' => 'everyone',
      ];
      $form['recipients'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Users'),
        '#target_type' => 'user',
        '#tags' => TRUE,
        '#selection_settings' => ['include_anonymous' => FALSE],
        '#description' => $this->t('Separate names with commas.'),
        '#states' => [
          'visible' => [':input[name="audience"]' => ['value' => 'selected']],
          'required' => [':input[name="audience"]' => ['value' => 'selected']],
        ],
      ];
    }
    else {
      $form['info'] = [
        '#markup' => '<p>' . $this->t('Recipients cannot be changed after sending. The notice can be edited only until someone acknowledges it.') . '</p>',
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $existing ? $this->t('Save') : $this->t('Send'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->get('notice_id') && $form_state->getValue('audience') === 'selected' && !$form_state->getValue('recipients')) {
      $form_state->setErrorByName('recipients', $this->t('Choose at least one user.'));
    }
    $id = $form_state->get('notice_id');
    if ($id && !$this->repository->isEditable((int) $id)) {
      $form_state->setErrorByName('', $this->t('Someone has already acknowledged this notice, so it can no longer be changed.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $title = (string) $form_state->getValue('title');
    $message = (string) $form_state->getValue('message');

    if ($id = $form_state->get('notice_id')) {
      $this->repository->update((int) $id, $title, $message);
      $this->messenger()->addStatus($this->t('The notice was updated.'));
    }
    else {
      $recipients = NULL;
      if ($form_state->getValue('audience') === 'selected') {
        $recipients = array_map(static fn (array $item): int => (int) $item['target_id'], (array) $form_state->getValue('recipients'));
      }
      $id = $this->repository->send((int) $this->currentUser()->id(), $title, $message, $recipients);
      $this->messenger()->addStatus($this->t('The notice was sent to @count people.', ['@count' => count($this->repository->recipients($id))]));
    }

    $form_state->setRedirectUrl(Url::fromRoute('acknowledgement_notices.view', ['notice' => $id]));
  }

}
