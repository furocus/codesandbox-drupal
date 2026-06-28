<?php

/**
 * @file
 * 冪等なモデル移行（新方向：技術者の学習リソース共助サイト）。
 *
 * 実行（※enable後）:
 *   docker compose exec -T drupal drush en -y link options
 *   docker compose exec -T drupal drush php:script \
 *     /opt/drupal/web/modules/custom/bookreview/scripts/setup_resources.php
 *
 * 生成:
 *  - タクソノミー tech_stack / target_audience（+ 代表 terms）
 *  - コンテンツタイプ book / article（最小フィールド）
 *  - review に field_resource(book/article参照) と可読性/実用性スコア(float 0-5)
 *  - Flag owned/want/favorite を book+article に適用
 *  - サンプル（技術書・記事・レビュー）
 */

use Drupal\node\Entity\NodeType;
use Drupal\node\Entity\Node;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;
use Drupal\flag\Entity\Flag;

function br_vocab(string $vid, string $name): void {
  if (!Vocabulary::load($vid)) {
    Vocabulary::create(['vid' => $vid, 'name' => $name])->save();
    echo "  [vocab] {$vid}\n";
  }
}
function br_type(string $id, string $name): void {
  if (!NodeType::load($id)) {
    NodeType::create(['type' => $id, 'name' => $name])->save();
    echo "  [type] {$id}\n";
  }
}
function br_field(string $entity, string $bundle, string $name, string $type, string $label, array $storage = [], array $settings = [], int $card = 1, bool $required = FALSE): void {
  if (!FieldStorageConfig::loadByName($entity, $name)) {
    FieldStorageConfig::create([
      'field_name' => $name, 'entity_type' => $entity, 'type' => $type,
      'cardinality' => $card, 'settings' => $storage,
    ])->save();
  }
  if (!FieldConfig::loadByName($entity, $bundle, $name)) {
    FieldConfig::create([
      'field_name' => $name, 'entity_type' => $entity, 'bundle' => $bundle,
      'label' => $label, 'required' => $required, 'settings' => $settings,
    ])->save();
    echo "  [field] {$entity}.{$bundle}.{$name}\n";
  }
}
function br_term(string $vid, string $name): int {
  $ex = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid, 'name' => $name]);
  if ($ex) {
    return (int) reset($ex)->id();
  }
  $t = Term::create(['vid' => $vid, 'name' => $name]);
  $t->save();
  return (int) $t->id();
}

echo "== 1. タクソノミー ==\n";
br_vocab('tech_stack', '技術スタック');
br_vocab('target_audience', '対象者');

echo "== 2. コンテンツタイプ ==\n";
br_type('book', '技術書籍 (Book)');
br_type('article', '技術記事 (Article)');

echo "== 3. book フィールド ==\n";
br_field('node', 'book', 'field_url', 'link', '購入/詳細URL');
br_field('node', 'book', 'field_author', 'string', '著者');
br_field('node', 'book', 'field_publisher', 'string', '出版社');
br_field('node', 'book', 'field_tech_stack', 'entity_reference', '技術スタック',
  ['target_type' => 'taxonomy_term'],
  ['handler' => 'default', 'handler_settings' => ['target_bundles' => ['tech_stack' => 'tech_stack']]],
  FieldStorageConfig::CARDINALITY_UNLIMITED);
br_field('node', 'book', 'field_target_audience', 'entity_reference', '対象者',
  ['target_type' => 'taxonomy_term'],
  ['handler' => 'default', 'handler_settings' => ['target_bundles' => ['target_audience' => 'target_audience']]],
  FieldStorageConfig::CARDINALITY_UNLIMITED);
br_field('node', 'book', 'field_description', 'text_long', '説明文');

echo "== 4. article フィールド ==\n";
br_field('node', 'article', 'field_url', 'link', '記事URL', [], [], 1, TRUE);
br_field('node', 'article', 'field_platform', 'list_string', 'プラットフォーム',
  ['allowed_values' => ['qiita' => 'Qiita', 'zenn' => 'Zenn', 'other' => 'その他']]);
br_field('node', 'article', 'field_author_handle', 'string', '投稿者');
// tech_stack / target_audience / description は book と同じ storage を共有（instance のみ作成）。
br_field('node', 'article', 'field_tech_stack', 'entity_reference', '技術スタック',
  ['target_type' => 'taxonomy_term'],
  ['handler' => 'default', 'handler_settings' => ['target_bundles' => ['tech_stack' => 'tech_stack']]],
  FieldStorageConfig::CARDINALITY_UNLIMITED);
br_field('node', 'article', 'field_target_audience', 'entity_reference', '対象者',
  ['target_type' => 'taxonomy_term'],
  ['handler' => 'default', 'handler_settings' => ['target_bundles' => ['target_audience' => 'target_audience']]],
  FieldStorageConfig::CARDINALITY_UNLIMITED);
br_field('node', 'article', 'field_description', 'text_long', '説明文');

echo "== 5. review フィールド（2軸スコア＋book/article参照） ==\n";
br_field('node', 'review', 'field_resource', 'entity_reference', 'レビュー対象',
  ['target_type' => 'node'],
  ['handler' => 'default', 'handler_settings' => ['target_bundles' => ['book' => 'book', 'article' => 'article']]],
  1, TRUE);
br_field('node', 'review', 'field_score_readability', 'float', '可読性スコア', [], ['min' => 0, 'max' => 5], 1, TRUE);
br_field('node', 'review', 'field_score_practicality', 'float', '実用性スコア', [], ['min' => 0, 'max' => 5], 1, TRUE);
// レビュー本文（非検索）。既存 body を使うため追加不要だが、無ければ付与。
if (!FieldConfig::loadByName('node', 'review', 'body')) {
  node_add_body_field(NodeType::load('review'), 'レビュー本文');
}
// 旧モデルの必須フィールド（field_item / field_rating）を任意化。
// 新モデルは field_resource + 2軸スコアを使うため、これらが必須のままだと
// JSON:API での新規レビュー投稿が 422（field_item/field_rating null）になる。
// データは消さず required を外すだけ（冪等・再実行安全）。
foreach (['field_item', 'field_rating'] as $legacy) {
  $fc = FieldConfig::loadByName('node', 'review', $legacy);
  if ($fc && $fc->isRequired()) {
    $fc->setRequired(FALSE)->save();
    echo "  [legacy] {$legacy} → optional\n";
  }
}

echo "== 6. Flag を book+article に適用 ==\n";
foreach (['owned', 'want', 'favorite'] as $fid) {
  $flag = Flag::load($fid);
  if ($flag) {
    $flag->set('bundles', ['book', 'article']);
    $flag->save();
    echo "  [flag] {$fid} → book,article\n";
  }
}

echo "== 7. サンプルデータ ==\n";
$stacks = [
  'React' => br_term('tech_stack', 'React'),
  'Go' => br_term('tech_stack', 'Go'),
  'Rust' => br_term('tech_stack', 'Rust'),
  'TypeScript' => br_term('tech_stack', 'TypeScript'),
  'Python' => br_term('tech_stack', 'Python'),
  'Docker' => br_term('tech_stack', 'Docker'),
  'Kubernetes' => br_term('tech_stack', 'Kubernetes'),
  'PHP' => br_term('tech_stack', 'PHP'),
];
$aud = [
  '初学者' => br_term('target_audience', '初学者'),
  '中級' => br_term('target_audience', '中級'),
  '上級' => br_term('target_audience', '上級'),
  '別スタック入門' => br_term('target_audience', '別スタックからの入門'),
];

$books = [
  ['t' => 'プログラミングRust', 'stack' => ['Rust'], 'aud' => ['中級'], 'pub' => 'オライリー', 'url' => 'https://example.com/rust', 'desc' => 'Rustの所有権と型システムを体系的に学べる定番。'],
  ['t' => 'Goプログラミング実践入門', 'stack' => ['Go'], 'aud' => ['初学者', '別スタック入門'], 'pub' => '技術評論社', 'url' => 'https://example.com/go', 'desc' => '他言語経験者がGoへ移るのに最適な入門書。'],
  ['t' => 'ロバストPython', 'stack' => ['Python'], 'aud' => ['中級'], 'pub' => 'オライリー', 'url' => 'https://example.com/robust-python', 'desc' => '型ヒントと堅牢な設計で保守しやすいPythonを書く。'],
  ['t' => 'Docker/Kubernetes 実践コンテナ開発入門', 'stack' => ['Docker', 'Kubernetes'], 'aud' => ['初学者'], 'pub' => '技術評論社', 'url' => 'https://example.com/k8s', 'desc' => 'コンテナの基礎からK8sでのデプロイまでを通しで学ぶ。'],
  ['t' => 'リーダブルなTypeScript', 'stack' => ['TypeScript'], 'aud' => ['中級'], 'pub' => '翔泳社', 'url' => 'https://example.com/ts', 'desc' => '型の表現力を活かした読みやすい設計パターン集。'],
  ['t' => 'プロフェッショナルPHP', 'stack' => ['PHP'], 'aud' => ['上級'], 'pub' => 'インプレス', 'url' => 'https://example.com/php', 'desc' => 'モダンPHPの設計・テスト・パフォーマンスを深掘り。'],
];
foreach ($books as $b) {
  $ex = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['type' => 'book', 'title' => $b['t']]);
  if ($ex) { continue; }
  $n = Node::create([
    'type' => 'book', 'title' => $b['t'], 'status' => 1,
    'field_url' => ['uri' => $b['url'], 'title' => $b['t']],
    'field_publisher' => $b['pub'],
    'field_description' => ['value' => $b['desc'], 'format' => 'basic_html'],
    'field_tech_stack' => array_map(fn($s) => ['target_id' => $stacks[$s]], $b['stack']),
    'field_target_audience' => array_map(fn($a) => ['target_id' => $aud[$a]], $b['aud']),
  ]);
  $n->save();
  echo "  [book] {$b['t']} (nid={$n->id()})\n";
}

$articles = [
  ['t' => 'React Hooks完全理解', 'stack' => ['React', 'TypeScript'], 'aud' => ['初学者'], 'plat' => 'zenn', 'url' => 'https://zenn.dev/example/hooks', 'handle' => '@example', 'desc' => 'useEffectの依存配列を初学者向けに丁寧に解説。'],
  ['t' => 'Goの並行処理パターン', 'stack' => ['Go'], 'aud' => ['上級'], 'plat' => 'qiita', 'url' => 'https://qiita.com/example/goroutine', 'handle' => '@gopher', 'desc' => 'goroutineとchannelの実戦的パターン集。'],
  ['t' => 'Python型ヒント入門', 'stack' => ['Python'], 'aud' => ['初学者'], 'plat' => 'zenn', 'url' => 'https://zenn.dev/example/typing', 'handle' => '@pytips', 'desc' => 'mypyで始める型付きPythonの第一歩。'],
  ['t' => 'Kubernetesマニフェスト設計のコツ', 'stack' => ['Kubernetes', 'Docker'], 'aud' => ['中級'], 'plat' => 'qiita', 'url' => 'https://qiita.com/example/k8s-manifest', 'handle' => '@sre', 'desc' => '本番運用で効くマニフェスト分割とレビュー観点。'],
  ['t' => 'TypeScriptで作る型安全API', 'stack' => ['TypeScript', 'React'], 'aud' => ['中級'], 'plat' => 'zenn', 'url' => 'https://zenn.dev/example/typed-api', 'handle' => '@tsdev', 'desc' => 'zod と型推論でフロント〜APIを一気通貫に。'],
  ['t' => 'Rustではじめる WebAssembly', 'stack' => ['Rust'], 'aud' => ['上級'], 'plat' => 'other', 'url' => 'https://example.com/rust-wasm', 'handle' => '@wasm', 'desc' => 'RustからWASMを吐いてブラウザで高速処理。'],
];
foreach ($articles as $a) {
  $ex = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['type' => 'article', 'title' => $a['t']]);
  if ($ex) { continue; }
  $n = Node::create([
    'type' => 'article', 'title' => $a['t'], 'status' => 1,
    'field_url' => ['uri' => $a['url'], 'title' => $a['t']],
    'field_platform' => $a['plat'],
    'field_author_handle' => $a['handle'],
    'field_description' => ['value' => $a['desc'], 'format' => 'basic_html'],
    'field_tech_stack' => array_map(fn($s) => ['target_id' => $stacks[$s]], $a['stack']),
    'field_target_audience' => array_map(fn($x) => ['target_id' => $aud[$x]], $a['aud']),
  ]);
  $n->save();
  echo "  [article] {$a['t']} (nid={$n->id()})\n";
}

echo "完了。\n";
