# ヘッドレスDrupal 技術検証メモ（本のレビュー&コレクション）

Drupal 11.3 / JSON:API（コア） / simple_oauth 6.1 / flag 5.0 / search_api 1.41 + facets を題材「本」で検証。
本ドキュメントが検証の成果物。各 Q への回答と「どこまで標準で行けて、どこからカスタムが要るか」を記録する。

セットアップは再実行可能なスクリプト化済み（いずれも冪等）：
- モデル(旧/本): `web/modules/custom/bookreview/scripts/setup_model.php`
- 認証: `web/modules/custom/bookreview/scripts/setup_auth.php`
- Flag: `web/modules/custom/bookreview/scripts/setup_flags.php`
- **モデル移行(新): `web/modules/custom/bookreview/scripts/setup_resources.php`**

## 題材ピボット：本のレビュー → 技術者の学習リソース共有サイト

検証基盤はそのままに、題材を「技術書・技術記事の学習リソース共有」へ移行（`setup_resources.php`）。
旧モデル（`item`/`field_item`/`field_rating` 単一評価）は retire（非公開）し、以下の新モデルへ：

- コンテンツタイプ `book`（技術書籍）/ `article`（技術記事）。タクソノミー `tech_stack`/`target_audience`。
- レビューは `field_resource`（book/article 参照, 必須）＋ **2軸スコア** `field_score_readability` / `field_score_practicality`（float 0-5）。
- Flag `owned`/`want`/`favorite` を book/article に適用（`like` は review のまま）。
- 旧必須フィールド `field_item`/`field_rating` は任意化（データは残すが新規投稿では不要。`setup_resources.php` が冪等に実施）。

**Q1〜Q3 は新モデルでも再検証済み**（フィールド名のみ読み替え。下記の `field_rating`→2軸スコア、`field_item`→`field_resource`、`node--item`→`node--book`/`node--article`）：
- Q1: `field_resource` 付き review を POST → **201**、owner 自動=alice(uid2)。
- Q3: book を `owned` で POST → **201**、DELETE(unflag) → **204**。

---

## Q1: JSON:API で認証ユーザーが書き込めるか / どこで詰まるか → ✅ 可能（要設定2つ）

**標準機能だけで書き込み可能**。ただし初期状態のままでは 405 になり、以下2点がハマりどころ。

1. **JSON:API はデフォルト読み取り専用**。`jsonapi.settings.read_only` を `false` にする必要がある。
   - 注意：`drush cset jsonapi.settings read_only false` は文字列 `"false"` 扱いで効かない。
     `drush ev "\Drupal::configFactory()->getEditable('jsonapi.settings')->set('read_only', FALSE)->save();"` のように boolean で設定する（または管理UI）。
2. **OAuth 秘密鍵のパーミッション**。`drush simple-oauth:generate-keys` が root 所有・600 で鍵を生成するため、Webサーバ（www-data）が読めず `server_error: You need to set the OAuth2 private key`。
   - `chown www-data:www-data` で解決。

成功例（201）：
```
POST /jsonapi/node/review   (Authorization: Bearer <token>, Content-Type: application/vnd.api+json)
{"data":{"type":"node--review",
  "attributes":{"title":"傑作SF","field_rating":5,"body":{"value":"...","format":"basic_html"}},
  "relationships":{"field_item":{"data":{"type":"node--item","id":"<book-uuid>"}}}}}
```
- 投稿者 `uid` は送らなくてよい。**Drupal が認証ユーザーを自動で所有者に設定**（owner uid=alice）。
- リレーション（対象の本）は `relationships` に UUID で渡す。

## Q2: 「自分の投稿だけ編集/削除」の権限がヘッドレスで効くか → ✅ 効く（標準権限のみ）

ロール `reviewer` に `create/edit own/delete own review content` を付与しただけで、JSON:API 越しに entity access が正しく適用される。別ユーザーに紐づくトークン2系統（alice / bob）で検証：

| 操作 | 結果 | 期待 |
|---|---|---|
| bob が alice のレビューを PATCH | 403 | 403 ✓ |
| bob が alice のレビューを DELETE | 403 | 403 ✓ |
| alice が自分のレビューを PATCH | 200（rating反映） | 200 ✓ |
| 匿名（トークン無）で POST | 401 | 拒否 ✓ |

→ **ヘッドレスでも権限はカスタムコード不要**。Drupal の node grants / 「own」権限がそのまま API に効く。

### 認証の重要な学び（simple_oauth 6.x）
- **password グラント（ROPC）は廃止**。利用可能なのは `authorization_code` / `client_credentials` / `refresh_token`。
- スコープは `oauth2_scope` 設定エンティティ（`scope_provider: dynamic`）。granularity=role でロールを付与する方式。
- 本検証は `client_credentials`（コンシューマに `user_id` を紐付け、そのユーザーとして発行）で実施。
  → 別ユーザーの2コンシューマを作ることで Q2 を検証できた。
- **実フロントの個別ユーザーログインは `authorization_code` + PKCE 必須**（client_credentials は M2M でユーザー固定）。段取り8で扱う。

### 既知の再現性ギャップ（要改善）
- OAuth 鍵を `/opt/drupal/keys`（コンテナローカル）に生成 → コンテナ再構築で消える。
  恒久化するなら keys 用ボリュームを docker-compose に追加するのが望ましい。
- `jsonapi.settings` / `simple_oauth.settings` 等の設定変更はまだ `drush cex` でリポジトリに未エクスポート（config同期先がハッシュ付きパスのため別途対応）。

---

## Q3: いいね/コレクションのリレーション配信（Flag） → ✅ 書込/権限OK（要カスタムフック3点）

Flag の `flagging` は JSON:API リソース `flagging--<flag_id>` として公開され、ヘッドレスで付け外し可能。
ただし**そのままでは動かず、3つのカスタム対応が必須**だった（`web/modules/custom/bookreview/src/Hook/BookreviewHooks.php`）。

作成したフラグ：`owned`/`want`/`favorite`（node:item）、`like`（node:review）。

### ハマりどころと対処（いずれも bookreview の OOPフックで解決）
1. **作成が 403「administer flaggings 必要」**。flagging は Flag専用ではなく**汎用 EntityAccessControlHandler** を使い、Flag のアクセスチェック（FlagAccessCheck等）は Flag のルート/リンク経由のみを守るため、JSON:API には効かない。
   → `#[Hook('flagging_create_access')]` で per-flag「flag &lt;id&gt;」権限に対応付け。
2. **422「entity_id should not be null」**。`flagged_entity` リレーションだけでは基底フィールド `entity_type`/`entity_id` が埋まらない（同期がJSON:APIバリデーション前に走らない）。
   → ペイロードに `attributes.entity_type=node` と `attributes.entity_id=<内部nid>` を送る（UUIDではなく内部ID）。
3. **500「Column 'uid' cannot be null」**。flagging は EntityOwnerInterface 非実装で uid 既定値が無い。
   → `#[Hook('flagging_presave')]` で新規時に現在ユーザーを強制（なりすまし防止も兼ねる）。
4. 削除は `#[Hook('entity_access')]` で「unflag &lt;id&gt;」権限 かつ オーナー本人のみ許可。

### 検証結果（ライフサイクル）
| 操作 | 結果 |
|---|---|
| alice が本を owned フラグ（POST） | 201 / owner 自動=alice |
| alice がレビューに like | 201 |
| bob が alice のフラグを DELETE | 403（オーナー制御）|
| alice が自分のフラグを DELETE（unflag） | 204 |

### 重要な落とし穴：flagging のフィルタは効かない
- `/jsonapi/flagging/like`（フィルタ無し）は取得できるが、`filter[entity_id]=5` 等は**常に空**を返す（flagging固有。node のフィールドフィルタは正常動作するので機構の問題ではない）。
- 結論：**「いいね数」「自分が付けたか」の集計はJSON:APIのflaggingフィルタに頼らない**。Q4 の computed field / カスタムエンドポイントに寄せるのが正解。

### .module の罠（Drupal 11.3）
- 手続き型 `.module` の **動的フック（hook_ENTITY_TYPE_presave 等）は新OOPフックシステムで解決されず**「Class &lt;fn&gt; does not exist」で500。
  → **OOP `#[Hook]` 属性のフッククラスに統一**して解決（`src/Hook/BookreviewHooks.php`）。

## Q4: 集計（平均スコア）の配信パターン → ✅ 完了（2方式を比較）

新モデルの2軸スコアを book/article ごとに集計する2方式を実装・検証（いずれも `field_resource` で参照を辿る）。

### 方式1：computed フィールド（読み取り時算出・JSON:API属性で自動公開）
- `web/modules/custom/bookreview/src/Field/AverageRatingItemList.php`（`score_field` 設定で2軸を切替）
  ＋ `BookreviewHooks::entityBundleFieldInfo`（book/article に `average_readability`/`average_practicality` を付与）。
- `GET /jsonapi/node/book` の各 attributes に平均が出る → **一覧取得だけで平均を取得でき追加リクエスト不要**。
  ```
  プログラミングRust          => average_readability:4.75 average_practicality:4.25
  Goプログラミング実践入門    => average_readability:4    average_practicality:4.5
  ```
- 長所：フロント実装が最小（普通の属性）。短所：1属性=1集計値（件数や複数指標をまとめにくい）。

### 方式3：カスタムエンドポイント（SQL集計を1レスポンスに）
- `web/modules/custom/bookreview/src/Controller/StatsController.php` ／ ルート `GET /api/resource/{node}/stats`。
  ```json
  // /api/resource/7/stats
  {"resource_id":7,"average_readability":4.75,"average_practicality":4.25,"review_count":2}
  ```
- `CacheableJsonResponse` ＋ キャッシュタグ `node_list:review`＋対象ノード依存で、レビュー追加/更新時に自動失効。
- 長所：複数軸＋件数を1リクエストにまとめられ最も柔軟。短所：エンドポイントを個別実装する必要。

### 使い分けの結論
- 一覧カードに平均だけ出すなら **方式1**（JSON:API include と相性良）。
- 件数や複数指標・将来の追加集計を見据えるなら **方式3**。
- どちらも entity access（公開レビューのみ集計、`accessCheck(TRUE)`）を尊重。

## Q5: ファセット検索（JSON:API filter vs Search API） → ✅ JSON:API filter で MVP 実装（Search API 版は今後）

検索一覧 MVP は **Search API/facets を使わず JSON:API filter のみ**で成立した。実機検証済み：
- タクソノミー参照の絞り込み：`filter[field_tech_stack.drupal_internal__tid]=<tid>` / `filter[field_target_audience.drupal_internal__tid]=<tid>`
- キーワード（タイトル部分一致）：`filter[t][condition][path]=title&...[operator]=CONTAINS&...[value]=<q>`
- 一覧表示：`include=field_tech_stack,field_target_audience` ＋ sparse fieldset で参照名と computed 平均を1リクエスト取得
- フィルタ選択肢：`/jsonapi/taxonomy_term/{tech_stack|target_audience}`

→ **少数ボキャブラリの単純ファセットなら JSON:API filter で十分**。Search API/facets が要るのは
全文検索・スコアリング・大規模集計ファセット（件数バッジ等）を求めるフェーズ。`search_api`/`facets` は
enable 済みなので、その検証は今後の課題として残す。

---

# フロントエンド MVP（Next.js · 検索一覧1ページ）

ヘッドレス配信を実際に「閲覧して機能確認」できる最小フロント。リポジトリ直下 `frontend/`。

- **構成**：Next.js 15（App Router / TypeScript）。`frontend/app/page.tsx`（検索一覧・サーバーコンポーネント）
  ＋ `frontend/app/_components/Filters.tsx`（フィルタUI・クライアント）＋ `frontend/lib/jsonapi.ts`（データ層・正規化）。
- **データ取得は server-side fetch**（Next → Drupal の server-to-server）。ブラウザから Drupal を直接叩かないため
  **CORS 設定は不要**。フィルタ状態は URL `searchParams`（`type`/`tech`/`aud`/`q`）で持ち、変更で再取得。
- **表示**：book/article を並列取得して結合、カードに 種別 / タグ(tech_stack・対象者) / 2軸平均スコアを表示。

### 起動手順
```sh
# 前提：Drupal が http://localhost:8080 で稼働（./setup.sh 済み）かつ setup_resources.php 適用済み
cd frontend
npm install
npm run dev          # http://localhost:3000
# 取得先は frontend/.env.local の DRUPAL_JSONAPI_BASE（既定 http://localhost:8080）
```

### 動作確認（実機）
- 全12件（book6 / article6）が一覧表示。
- `?tech=10`（Rust）→ プログラミングRust(書籍) ＋ Rustではじめる WebAssembly(記事) の2件に絞り込み。
- `?type=book&tech=10` → プログラミングRust の1件。`?q=Python` → Python関連2件。`?aud=14`（上級）→ 3件。
- レビュー有りリソース（プログラミングRust）は平均スコア（可読性4.5 / 実用性4）をカードに表示。
- `npm run build` 成功（型OK、`/` は動的レンダリング）。

### スコープ外（次フェーズ候補）
詳細ページ、ユーザーログイン(authorization_code+PKCE)、レビュー投稿UI、Flag操作UI、Search API ファセット。
