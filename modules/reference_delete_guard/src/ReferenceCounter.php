<?php

declare(strict_types=1);

namespace Drupal\reference_delete_guard;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Counts what still points to an entity.
 *
 * Every configurable entity reference field of every content entity type is
 * checked, including fields created after this module was enabled. Base
 * fields (author, revision user, parent term...) are ignored: they belong to
 * the entity itself, not to relationships the site builder created.
 */
class ReferenceCounter {

  /**
   * Field types that store a target_id pointing to another entity.
   */
  protected const REFERENCE_FIELD_TYPES = ['entity_reference', 'entity_reference_revisions'];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
  ) {}

  /**
   * The settings, also usable as a cacheable dependency.
   */
  public function config(): ImmutableConfig {
    return $this->configFactory->get('reference_delete_guard.settings');
  }

  /**
   * Whether this entity's bundle is protected.
   */
  public function isProtected(EntityInterface $entity): bool {
    return in_array($entity->getEntityTypeId() . ':' . $entity->bundle(), (array) $this->config()->get('protected'), TRUE);
  }

  /**
   * How many entities of each kind reference the given one.
   *
   * @return array<string, int>
   *   Human label of the referencing bundle => number of entities, only for
   *   kinds with at least one reference. Empty when nothing points to it.
   */
  public function count(EntityInterface $target): array {
    if ($target->isNew()) {
      return [];
    }

    $totals = [];
    foreach ($this->referencingFields($target->getEntityTypeId()) as [$source_type, $field_name, $bundles]) {
      $storage = $this->entityTypeManager->getStorage($source_type);
      $definition = $this->entityTypeManager->getDefinition($source_type);
      $bundle_key = $definition->getKey('bundle');

      foreach ($bundles as $bundle) {
        $query = $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition($field_name, $target->id());
        if ($bundle_key) {
          $query->condition($bundle_key, $bundle);
        }
        // An entity that references itself does not block its own deletion.
        if ($source_type === $target->getEntityTypeId()) {
          $query->condition($definition->getKey('id'), $target->id(), '<>');
        }
        $count = (int) $query->count()->execute();
        if ($count > 0) {
          $label = $this->label($source_type, $bundle);
          $totals[$label] = ($totals[$label] ?? 0) + $count;
        }
      }
    }

    return $totals;
  }

  /**
   * The references in one phrase: "3 Invoice, 1 Payment and 2 User".
   *
   * @param array<string, int> $counts
   *   The result of count().
   */
  public function summarize(array $counts): string {
    $parts = [];
    foreach ($counts as $label => $count) {
      $parts[] = $count . ' ' . $label;
    }
    $last = array_pop($parts);

    return $parts ? implode(', ', $parts) . ' and ' . $last : (string) $last;
  }

  /**
   * Cache tags that change whenever a reference could appear or disappear.
   *
   * @return string[]
   *   The list cache tags of every entity type that can reference the given
   *   entity type.
   */
  public function cacheTags(string $entity_type_id): array {
    $tags = [];
    foreach ($this->referencingFields($entity_type_id) as [$source_type]) {
      $tags = array_merge($tags, $this->entityTypeManager->getDefinition($source_type)->getListCacheTags());
    }

    return array_values(array_unique($tags));
  }

  /**
   * Configurable reference fields that can point to the given entity type.
   *
   * @return array<int, array{0: string, 1: string, 2: string[]}>
   *   [source entity type, field name, bundles where the field exists].
   */
  protected function referencingFields(string $target_type): array {
    $fields = [];
    foreach (self::REFERENCE_FIELD_TYPES as $field_type) {
      foreach ($this->entityFieldManager->getFieldMapByFieldType($field_type) as $source_type => $map) {
        $storage_definitions = $this->entityFieldManager->getFieldStorageDefinitions($source_type);
        foreach ($map as $field_name => $info) {
          $storage_definition = $storage_definitions[$field_name] ?? NULL;
          if ($storage_definition === NULL || $storage_definition->isBaseField()
            || $storage_definition->getSetting('target_type') !== $target_type) {
            continue;
          }
          $fields[] = [$source_type, $field_name, array_values($info['bundles'])];
        }
      }
    }

    return $fields;
  }

  /**
   * The label people know for the referencing bundle.
   */
  protected function label(string $entity_type_id, string $bundle): string {
    $bundles = $this->bundleInfo->getBundleInfo($entity_type_id);

    return (string) ($bundles[$bundle]['label'] ?? $this->entityTypeManager->getDefinition($entity_type_id)->getLabel());
  }

}
