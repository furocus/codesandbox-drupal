<?php

/**
 * @file
 * 冪等な認証セットアップ（simple_oauth 6.x / client_credentials 検証用）。
 *
 * 実行:
 *   docker compose exec -T drupal drush php:script \
 *     /opt/drupal/web/modules/custom/bookreview/scripts/setup_auth.php
 *
 * 生成物:
 *  - ロール reviewer（review作成 / 自分のreview編集・削除）
 *  - スコープ reviewer（granularity=role → reviewer ロールを付与）
 *  - ユーザー alice / bob（reviewer ロール）
 *  - コンシューマ 2つ（alice用 / bob用, client_credentials 有効）
 *    → client_id / secret を出力。これで「別ユーザーとしてのトークン」を2系統得て
 *      Q2（自分の投稿だけ編集）を検証する。
 */

use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth\Entity\Oauth2Scope;

/** ロールを冪等作成し、権限を付与 */
function bk_role(string $id, string $label, array $perms): void {
  $role = Role::load($id);
  if (!$role) {
    $role = Role::create(['id' => $id, 'label' => $label]);
    $role->save();
    echo "  [role] {$id} created\n";
  }
  foreach ($perms as $p) {
    $role->grantPermission($p);
  }
  $role->save();
}

/** ユーザーを冪等作成（固定パスワード） */
function bk_user(string $name, string $pass, array $roles): User {
  $users = \Drupal::entityTypeManager()->getStorage('user')
    ->loadByProperties(['name' => $name]);
  if ($users) {
    return reset($users);
  }
  $u = User::create([
    'name' => $name,
    'pass' => $pass,
    'mail' => "{$name}@example.com",
    'status' => 1,
    'roles' => $roles,
  ]);
  $u->save();
  echo "  [user] {$name} created (uid={$u->id()})\n";
  return $u;
}

/** コンシューマを冪等作成（client_credentials, 指定ユーザーに紐付け） */
function bk_consumer(string $client_id, string $label, string $secret, int $uid, array $scopes): void {
  $existing = \Drupal::entityTypeManager()->getStorage('consumer')
    ->loadByProperties(['client_id' => $client_id]);
  if ($existing) {
    echo "  [consumer] {$client_id} exists\n";
    return;
  }
  $c = Consumer::create([
    'client_id' => $client_id,
    'label' => $label,
    'secret' => $secret,
    'confidential' => TRUE,
    'is_default' => FALSE,
    'grant_types' => ['client_credentials', 'refresh_token'],
    'user_id' => $uid,
    'scopes' => array_map(fn($s) => ['scope_id' => $s], $scopes),
  ]);
  $c->save();
  echo "  [consumer] {$client_id} created → uid={$uid}, secret={$secret}\n";
}

echo "== 1. ロール ==\n";
// JSON:API の書き込みは Drupal のエンティティ/フィールドアクセスに従う。
// review 作成・自分のreview編集削除・閲覧・テキストフォーマット使用を付与。
bk_role('reviewer', 'Reviewer', [
  'access content',
  'create review content',
  'edit own review content',
  'delete own review content',
  'use text format basic_html',
]);

echo "== 2. スコープ（granularity=role） ==\n";
if (!Oauth2Scope::load('reviewer')) {
  Oauth2Scope::create([
    'id' => 'reviewer',
    'name' => 'reviewer',
    'description' => 'Reviewer role scope',
    'grant_types' => [
      'client_credentials' => ['status' => TRUE, 'description' => 'Client credentials'],
      'authorization_code' => ['status' => TRUE, 'description' => 'Authorization code'],
      'refresh_token' => ['status' => TRUE, 'description' => 'Refresh token'],
    ],
    'umbrella' => FALSE,
    'granularity_id' => 'role',
    'granularity_configuration' => ['role' => 'reviewer'],
  ])->save();
  echo "  [scope] reviewer created\n";
}

echo "== 3. ユーザー ==\n";
$alice = bk_user('alice', 'alice-pass', ['reviewer']);
$bob = bk_user('bob', 'bob-pass', ['reviewer']);

echo "== 4. コンシューマ（client_credentials） ==\n";
bk_consumer('alice_client', 'Alice client', 'alice-secret', (int) $alice->id(), ['reviewer']);
bk_consumer('bob_client', 'Bob client', 'bob-secret', (int) $bob->id(), ['reviewer']);

echo "完了。\n";
