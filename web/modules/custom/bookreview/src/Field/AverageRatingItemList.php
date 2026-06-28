<?php

declare(strict_types=1);

namespace Drupal\bookreview\Field;

use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;

/**
 * リソース(book/article)のレビュー平均スコアを算出する computed フィールド
 * （Q4 検証・方式1）。
 *
 * 保存せず読み取り時に算出。JSON:API では node--book / node--article の通常の
 * 属性として自動公開されるため、フロントは一覧取得だけで平均スコアを得られる
 * （追加リクエスト不要）。
 *
 * どのスコアを平均するかはフィールド定義の設定 `score_field` で切替（2軸対応）：
 *   - field_score_readability（可読性）
 *   - field_score_practicality（実用性）
 * レビューはリソースを `field_resource` で参照する。
 */
final class AverageRatingItemList extends FieldItemList {

  use ComputedItemListTrait;

  protected function computeValue(): void {
    $entity = $this->getEntity();
    if ($entity->isNew()) {
      $this->list[0] = $this->createItem(0, NULL);
      return;
    }

    // 平均対象のスコアフィールド名（既定は可読性）。
    $score_field = $this->getSetting('score_field') ?: 'field_score_readability';

    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'review')
      ->condition('status', 1)
      ->condition('field_resource', $entity->id())
      ->execute();

    $sum = 0.0;
    $count = 0;
    if ($ids) {
      foreach ($storage->loadMultiple($ids) as $review) {
        if ($review->hasField($score_field) && !$review->get($score_field)->isEmpty()) {
          $sum += (float) $review->get($score_field)->value;
          $count++;
        }
      }
    }

    $average = $count > 0 ? round($sum / $count, 2) : NULL;
    $this->list[0] = $this->createItem(0, $average);
  }

}
