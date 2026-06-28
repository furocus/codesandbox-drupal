<?php

/**
 * @file
 * 冪等なモデル構築スクリプト（本＝Item / Review / タクソノミー）。
 *
 * 実行:
 *   docker compose exec -T drupal drush php:script \
 *     /opt/drupal/web/modules/custom/bookreview/scripts/setup_model.php
 *
 * JSON:API はフィールド表示(display)に依存せず全フィールドを公開するため、
 * ここでは storage / instance のみ作成し、form/view display は最小限に留める。
 */

use Drupal\node\Entity\NodeType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;
use Drupal\node\Entity\Node;

/** タクソノミー語彙を冪等作成 */
function bk_vocab(string $vid, string $name): void {
  if (!Vocabulary::load($vid)) {
    Vocabulary::create(['vid' => $vid, 'name' => $name])->save();
    echo "  [vocab] {$vid} created\n";
  }
}

/** コンテンツタイプを冪等作成 */
function bk_type(string $id, string $name): void {
  if (!NodeType::load($id)) {
    NodeType::create(['type' => $id, 'name' => $name])->save();
    echo "  [type] {$id} created\n";
  }
}

/**
 * フィールド storage + instance を冪等作成。
 *
 * @param array $storage_settings  storage 側 settings（target_type 等）
 * @param array $field_settings     instance 側 settings（min/max, handler 等）
 */
function bk_field(string $entity, string $bundle, string $name, string $type, string $label, array $storage_settings = [], array $field_settings = [], int $cardinality = 1, bool $required = FALSE): void {
  if (!FieldStorageConfig::loadByName($entity, $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entity,
      'type' => $type,
      'cardinality' => $cardinality,
      'settings' => $storage_settings,
    ])->save();
  }
  if (!FieldConfig::loadByName($entity, $bundle, $name)) {
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entity,
      'bundle' => $bundle,
      'label' => $label,
      'required' => $required,
      'settings' => $field_settings,
    ])->save();
    echo "  [field] {$entity}.{$bundle}.{$name} created\n";
  }
}

echo "== 1. タクソノミー ==\n";
bk_vocab('genre', 'ジャンル');
bk_vocab('book_author', '著者');

echo "== 2. コンテンツタイプ ==\n";
bk_type('item', '本 (Item)');
bk_type('review', 'レビュー (Review)');

echo "== 3. Item フィールド ==\n";
// 表紙画像（単一）
bk_field('node', 'item', 'field_cover', 'image', '表紙画像');
// 著者（taxonomy: book_author 参照, 単一）
bk_field('node', 'item', 'field_author', 'entity_reference', '著者',
  ['target_type' => 'taxonomy_term'],
  ['handler' => 'default', 'handler_settings' => ['target_bundles' => ['book_author' => 'book_author']]],
  1, TRUE);
// ジャンル（taxonomy: genre 参照, 複数）
bk_field('node', 'item', 'field_genre', 'entity_reference', 'ジャンル',
  ['target_type' => 'taxonomy_term'],
  ['handler' => 'default', 'handler_settings' => ['target_bundles' => ['genre' => 'genre']]],
  FieldStorageConfig::CARDINALITY_UNLIMITED, FALSE);
// 出版年
bk_field('node', 'item', 'field_published_year', 'integer', '出版年');
// ページ数
bk_field('node', 'item', 'field_page_count', 'integer', 'ページ数');
// ISBN
bk_field('node', 'item', 'field_isbn', 'string', 'ISBN');

echo "== 4. Review フィールド ==\n";
// 対象の本（node:item 参照, 必須）
bk_field('node', 'review', 'field_item', 'entity_reference', '対象の本',
  ['target_type' => 'node'],
  ['handler' => 'default', 'handler_settings' => ['target_bundles' => ['item' => 'item']]],
  1, TRUE);
// 評価（1-5）
bk_field('node', 'review', 'field_rating', 'integer', '評価',
  [], ['min' => 1, 'max' => 5], 1, TRUE);

// body（説明 / レビュー本文）を両タイプに付与
node_add_body_field(NodeType::load('item'), '説明');
node_add_body_field(NodeType::load('review'), 'レビュー本文');
echo "  [field] body added to item/review\n";

echo "== 5. サンプルデータ ==\n";
/** タクソノミー語を冪等作成して tid を返す */
function bk_term(string $vid, string $name): int {
  $existing = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
    ->loadByProperties(['vid' => $vid, 'name' => $name]);
  if ($existing) {
    return (int) reset($existing)->id();
  }
  $t = Term::create(['vid' => $vid, 'name' => $name]);
  $t->save();
  return (int) $t->id();
}

$genres = [
  'SF' => bk_term('genre', 'SF'),
  'ミステリ' => bk_term('genre', 'ミステリ'),
  '技術書' => bk_term('genre', '技術書'),
  'ファンタジー' => bk_term('genre', 'ファンタジー'),
];
$authors = [
  '伊藤計劃' => bk_term('book_author', '伊藤計劃'),
  '宮部みゆき' => bk_term('book_author', '宮部みゆき'),
  'Martin Fowler' => bk_term('book_author', 'Martin Fowler'),
];

$books = [
  ['title' => '虐殺器官', 'author' => '伊藤計劃', 'genre' => ['SF'], 'year' => 2007, 'pages' => 416],
  ['title' => 'ハーモニー', 'author' => '伊藤計劃', 'genre' => ['SF'], 'year' => 2008, 'pages' => 384],
  ['title' => '火車', 'author' => '宮部みゆき', 'genre' => ['ミステリ'], 'year' => 1992, 'pages' => 597],
  ['title' => 'リファクタリング', 'author' => 'Martin Fowler', 'genre' => ['技術書'], 'year' => 2018, 'pages' => 464],
];

foreach ($books as $b) {
  $existing = \Drupal::entityTypeManager()->getStorage('node')
    ->loadByProperties(['type' => 'item', 'title' => $b['title']]);
  if ($existing) {
    continue;
  }
  $node = Node::create([
    'type' => 'item',
    'title' => $b['title'],
    'field_author' => ['target_id' => $authors[$b['author']]],
    'field_genre' => array_map(fn($g) => ['target_id' => $genres[$g]], $b['genre']),
    'field_published_year' => $b['year'],
    'field_page_count' => $b['pages'],
    'status' => 1,
  ]);
  $node->save();
  echo "  [item] {$b['title']} (nid={$node->id()})\n";
}

echo "完了。\n";
