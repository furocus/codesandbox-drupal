<?php

declare(strict_types=1);

namespace Drupal\bookreview\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;

/**
 * 集計値の配信（Q4 検証・方式3: カスタムエンドポイント）。
 *
 * GET /api/resource/{node}/stats
 *   → {resource_id, average_readability, average_practicality, review_count}
 *
 * SQL集計を1リクエストで返せるため最も柔軟（方式1の computed フィールドが
 * 1属性=1平均なのに対し、複数軸/件数をまとめて返せる）。Cache メタデータを
 * 付けてレビュー更新時に自動失効させる。entity_type.manager は ControllerBase
 * 既定の $this->entityTypeManager() を利用（再注入しない）。
 */
final class StatsController extends ControllerBase {

  public function itemStats(NodeInterface $node): CacheableJsonResponse {
    $review_storage = $this->entityTypeManager()->getStorage('node');

    // このリソース(book/article)を参照する公開レビューの2軸スコアを集計。
    $ids = $review_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'review')
      ->condition('status', 1)
      ->condition('field_resource', $node->id())
      ->execute();

    $count = 0;
    $sum_read = 0.0;
    $sum_prac = 0.0;
    if ($ids) {
      foreach ($review_storage->loadMultiple($ids) as $review) {
        if (!$review->get('field_score_readability')->isEmpty()) {
          $sum_read += (float) $review->get('field_score_readability')->value;
        }
        if (!$review->get('field_score_practicality')->isEmpty()) {
          $sum_prac += (float) $review->get('field_score_practicality')->value;
        }
        $count++;
      }
    }

    $data = [
      'resource_id' => (int) $node->id(),
      'average_readability' => $count > 0 ? round($sum_read / $count, 2) : NULL,
      'average_practicality' => $count > 0 ? round($sum_prac / $count, 2) : NULL,
      'review_count' => $count,
    ];

    // レビュー追加/削除（node_list:review）と本体更新で失効するキャッシュタグを付与。
    $cacheability = (new CacheableMetadata())
      ->addCacheTags(['node_list:review'])
      ->addCacheableDependency($node);

    $response = new CacheableJsonResponse($data);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

}
