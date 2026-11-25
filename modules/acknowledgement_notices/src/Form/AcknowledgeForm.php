<?php

declare(strict_types=1);

namespace Drupal\acknowledgement_notices\Form;

use Drupal\acknowledgement_notices\NoticeRepository;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The "I have read and understood" button.
 *
 * Shown inside the overlay on every page, and posted to its own route so the
 * acknowledgement never depends on the page being displayed.
 */
class AcknowledgeForm extends FormBase {

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
    return 'acknowledgement_notices_acknowledge';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int|string|null $notice = NULL): array {
    $form['notice'] = ['#type' => 'value', '#value' => (int) $notice];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('I have read and understood'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $id = (int) $form_state->getValue('notice');
    if (!$this->repository->isPendingFor($id, (int) $this->currentUser()->id())) {
      $form_state->setErrorByName('', $this->t('This notice is no longer waiting for you.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->repository->acknowledge((int) $form_state->getValue('notice'), (int) $this->currentUser()->id());
    // The ?destination= query brings the user back to the page they were on.
    $form_state->setRedirectUrl(Url::fromRoute('<front>'));
  }

}
