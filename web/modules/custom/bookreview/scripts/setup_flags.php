<?php

/**
 * @file
 * 冪等な Flag セットアップ（コレクション/いいね）。
 *
 * 実行:
 *   docker compose exec -T drupal drush php:script \
 *     /opt/drupal/web/modules/custom/bookreview/scripts/setup_flags.php
 *
 * 生成フラグ:
 *  - owned    / want / favorite  : node:item へのコレクション状態
 *  - like                        : node:review へのいいね
 * いずれも flagging エンティティとして JSON:API (/jsonapi/flagging/<id>) で付け外しする。
 */

use Drupal\flag\Entity\Flag;
use Drupal\user\Entity\Role;

/** フラグを冪等作成 */
function bk_flag(string $id, string $label, string $bundle): void {
  if (Flag::load($id)) {
    echo "  [flag] {$id} exists\n";
    return;
  }
  Flag::create([
    'id' => $id,
    'label' => $label,
    'entity_type' => 'node',
    'bundles' => [$bundle],
    'flag_type' => 'entity:node',
    'link_type' => 'reload',
    'flagTypeConfig' => [
      'show_as_field' => TRUE,
      'show_on_form' => FALSE,
      'show_contextual_links' => FALSE,
      'show_in_links' => [],
      'extra_permissions' => [],
    ],
    'linkTypeConfig' => [],
    'global' => FALSE,
    'flag_short' => $label,
    'unflag_short' => $label . ' 解除',
  ])->save();
  echo "  [flag] {$id} created (node:{$bundle})\n";
}

echo "== フラグ作成 ==\n";
bk_flag('owned', '持ってる', 'item');
bk_flag('want', '欲しい', 'item');
bk_flag('favorite', 'お気に入り', 'item');
bk_flag('like', 'いいね', 'review');

echo "== reviewer ロールへ flag/unflag 権限付与 ==\n";
$role = Role::load('reviewer');
foreach (['owned', 'want', 'favorite', 'like'] as $fid) {
  $role->grantPermission("flag {$fid}");
  $role->grantPermission("unflag {$fid}");
}
$role->save();
echo "  権限付与完了\n";

echo "完了。\n";
