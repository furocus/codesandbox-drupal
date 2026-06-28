<?php

declare(strict_types=1);

namespace Drupal\bookreview\Hook;

use Drupal\bookreview\Field\AverageRatingItemList;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Flag の flagging エンティティをヘッドレス(JSON:API)で扱えるようにするフック群。
 *
 * Drupal 11.3 の新OOPフックシステム（#[Hook] 属性）で実装。
 * 手続き型 .module の動的フック（hook_ENTITY_TYPE_presave 等）は新システムで
 * 正しく解決されず「Class does not exist」になるため、OOP に統一している。
 *
 * 解決する3つの問題:
 *  1) 作成アクセス: flagging は汎用 EntityAccessControlHandler を使い、admin_permission
 *     (administer flaggings) が無いと拒否される。per-flag「flag <id>」権限に対応付ける。
 *  2) uid 未設定: flagging は EntityOwnerInterface 非実装で uid 既定値が無く、
 *     ヘッドレス作成では uid=NULL の DB 制約違反になる。新規時に現在ユーザーを強制。
 *  3) 削除アクセス: 「unflag <id>」権限 かつ オーナー本人のみ削除可。
 */
final class BookreviewHooks implements ContainerInjectionInterface {

  public function __construct(
    protected readonly AccountProxyInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('current_user'));
  }

  /**
   * 新規 flagging の所有者を現在の認証ユーザーに強制（なりすまし防止＋uid必須充足）。
   */
  #[Hook('flagging_presave')]
  public function flaggingPresave(EntityInterface $entity): void {
    if ($entity->isNew()) {
      $entity->set('uid', $this->currentUser->id());
    }
  }

  /**
   * flagging 作成を per-flag「flag <flag_id>」権限に対応付ける。
   */
  #[Hook('flagging_create_access')]
  public function flaggingCreateAccess(AccountInterface $account, array $context, string $entity_bundle): AccessResultInterface {
    if ($account->hasPermission("flag {$entity_bundle}")) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    return AccessResult::neutral();
  }

  /**
   * flagging の view は公開、delete はオーナー かつ「unflag <flag_id>」権限のみ許可。
   */
  #[Hook('entity_access')]
  public function entityAccess(EntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
    if ($entity->getEntityTypeId() !== 'flagging') {
      return AccessResult::neutral();
    }
    $flag_id = $entity->bundle();

    if ($operation === 'view') {
      return AccessResult::allowed();
    }

    if ($operation === 'delete') {
      $is_owner = (int) $entity->get('uid')->target_id === (int) $account->id();
      if ($is_owner && $account->hasPermission("unflag {$flag_id}")) {
        return AccessResult::allowed()
          ->cachePerPermissions()
          ->cachePerUser()
          ->addCacheableDependency($entity);
      }
    }

    return AccessResult::neutral();
  }

  /**
   * book/article に2軸の computed 平均スコアを追加（Q4 検証・方式1）。
   *
   * 保存せず読み取り時に算出し、JSON:API で node--book / node--article の属性
   * として自動公開される。フロントは一覧取得だけで平均を得られ追加リクエスト不要。
   * レビューは field_resource でリソースを参照し、可読性/実用性の2軸を平均する。
   */
  #[Hook('entity_bundle_field_info')]
  public function entityBundleFieldInfo(EntityTypeInterface $entity_type, string $bundle, array $base_field_definitions): array {
    $fields = [];
    if ($entity_type->id() === 'node' && in_array($bundle, ['book', 'article'], TRUE)) {
      $fields['average_readability'] = BaseFieldDefinition::create('float')
        ->setLabel('平均可読性スコア')
        ->setComputed(TRUE)
        ->setClass(AverageRatingItemList::class)
        ->setSetting('score_field', 'field_score_readability')
        ->setReadOnly(TRUE);
      $fields['average_practicality'] = BaseFieldDefinition::create('float')
        ->setLabel('平均実用性スコア')
        ->setComputed(TRUE)
        ->setClass(AverageRatingItemList::class)
        ->setSetting('score_field', 'field_score_practicality')
        ->setReadOnly(TRUE);
    }
    return $fields;
  }

}
