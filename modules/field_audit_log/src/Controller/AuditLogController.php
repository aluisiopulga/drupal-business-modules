<?php

declare(strict_types=1);

namespace Drupal\field_audit_log\Controller;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\field_audit_log\AuditLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The audit log report.
 *
 * Filters come from the query string, so any row can link to "everything
 * that happened to this item" or "everything this user did":
 * ?entity_type=node&entity_id=12 or ?uid=3.
 */
class AuditLogController extends ControllerBase {

  protected const PER_PAGE = 50;

  public function __construct(
    protected AuditLogger $auditLogger,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('field_audit_log.logger'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the report page.
   */
  public function report(Request $request): array {
    $filters = array_filter([
      'entity_type' => (string) $request->query->get('entity_type', ''),
      'entity_id' => (string) $request->query->get('entity_id', ''),
      'uid' => $request->query->has('uid') ? (int) $request->query->get('uid') : NULL,
    ], static fn ($value) => $value !== '' && $value !== NULL);

    $page = max(0, (int) $request->query->get('page', 0));
    $rows = $this->auditLogger->entries($filters, self::PER_PAGE + 1, $page * self::PER_PAGE);
    $has_next = count($rows) > self::PER_PAGE;
    $rows = array_slice($rows, 0, self::PER_PAGE);

    $build['filters'] = [
      '#markup' => $filters
        ? '<p>' . $this->t('Filtered. <a href=":url">Show everything</a>.', [':url' => Url::fromRoute('field_audit_log.report')->toString()]) . '</p>'
        : '',
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('When'),
        $this->t('Who'),
        $this->t('Action'),
        $this->t('Content'),
        $this->t('Where'),
        $this->t('Changes'),
      ],
      '#empty' => $this->t('No changes recorded yet.'),
      '#rows' => array_map(fn (object $row): array => $this->row($row), $rows),
    ];

    $build['pager'] = $this->pager($filters, $page, $has_next);
    $build['#cache']['max-age'] = 0;

    return $build;
  }

  /**
   * One table row.
   */
  protected function row(object $row): array {
    $user = $row->uid ? $this->entityTypeManager()->getStorage('user')->load($row->uid) : NULL;
    $who = $row->uid ? ($user ? $user->getDisplayName() : $this->t('Deleted user @uid', ['@uid' => $row->uid])) : $this->t('System');

    $changes = [];
    foreach ($row->changes as $field => [$before, $after]) {
      $changes[] = match ($row->action) {
        'create' => "$field: " . $this->short($after),
        'delete' => "$field: " . $this->short($before),
        default => "$field: " . $this->short($before) . ' → ' . $this->short($after),
      };
    }

    return [
      $this->dateFormatter->format((int) $row->timestamp, 'short'),
      [
        'data' => [
          '#type' => 'link',
          '#title' => $who,
          '#url' => $this->reportUrl(['uid' => $row->uid]),
        ],
      ],
      $row->action,
      [
        'data' => [
          '#type' => 'link',
          '#title' => $row->label . ' (' . $row->entity_type . ' ' . $row->entity_id . ')',
          '#url' => $this->reportUrl([
            'entity_type' => $row->entity_type,
            'entity_id' => $row->entity_id,
          ]),
        ],
      ],
      $row->path,
      ['data' => ['#theme' => 'item_list', '#items' => $changes]],
    ];
  }

  /**
   * Shortens long values for the table.
   */
  protected function short(?string $value): string {
    return $value === NULL || $value === '' ? '∅' : Unicode::truncate($value, 80, TRUE, TRUE);
  }

  /**
   * A simple previous/next pager that keeps the filters.
   */
  protected function pager(array $filters, int $page, bool $has_next): array {
    $links = [];
    if ($page > 0) {
      $links[] = [
        '#type' => 'link',
        '#title' => $this->t('‹ Newer'),
        '#url' => $this->reportUrl($filters + ['page' => $page - 1]),
      ];
    }
    if ($has_next) {
      $links[] = [
        '#type' => 'link',
        '#title' => $this->t('Older ›'),
        '#url' => $this->reportUrl($filters + ['page' => $page + 1]),
      ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $links,
      '#attributes' => ['class' => ['field-audit-log-pager']],
    ];
  }

  /**
   * The report URL with the given query string.
   */
  protected function reportUrl(array $query): Url {
    return Url::fromRoute('field_audit_log.report', [], ['query' => $query]);
  }

}
