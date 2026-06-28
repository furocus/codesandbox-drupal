# 引継ぎ（HANDOFF）— ヘッドレス学習リソース基盤 + Next.js 検索MVP

次回セッションが「どこから再開するか」を1枚で掴むためのメモ。詳細な検証ログは
[`verification-notes.md`](./verification-notes.md) を参照。

## いまの状態（done）

- **バックエンド（Drupal 11 / `web/modules/custom/bookreview`）**
  - 題材を「技術者の学習リソース共有サイト」へピボット済み：`book`/`article` コンテンツタイプ、
    タクソノミー `tech_stack`/`target_audience`、`review` に `field_resource`＋2軸スコア
    （`field_score_readability`/`field_score_practicality`）。
  - 生成は冪等スクリプト `scripts/setup_resources.php`（`drush php:script`）。サンプル book6/article6。
  - 検証済み：**Q1**=認証付きJSON:API書込(201)、**Q2**=own権限、**Q3**=Flag(201/204)、
    **Q4**=集計2方式（computed属性 / `/api/resource/{node}/stats`）。**Q5**=JSON:API filter で検索実装。
- **フロントエンド（`frontend/` / Next.js 15 App Router）**
  - 検索一覧1ページ（`app/page.tsx`）。`lib/jsonapi.ts` が JSON:API を**サーバーサイド取得**（CORS不要）。
    tech_stack / target_audience / キーワードで絞り込み、平均スコア表示。
- **リポジトリ衛生**：ルート `.gitignore`＋`.env.example` 整備（`.env`・`mysql/data`・`node_modules` 等を除外）。

## 動かし方

```sh
# 1) バックエンド（初回）
./setup.sh
# 2) モデル投入（冪等。再実行で最新化）
docker compose exec -T drupal drush php:script \
  /opt/drupal/web/modules/custom/bookreview/scripts/setup_resources.php
docker compose exec -T drupal drush cr
# 3) フロント
cd frontend && npm install && npm run dev        # http://localhost:3000
#    取得先は frontend/.env.local の DRUPAL_JSONAPI_BASE（既定 http://localhost:8080）
```

## 次の一手（おすすめ順）

1. **詳細ページ**（`app/[type]/[id]/page.tsx`）：1リソース＋平均スコア＋レビュー一覧。
2. **ユーザーログイン**：simple_oauth の `authorization_code`＋PKCE コンシューマを用意（現状は M2M の
   `client_credentials` のみ）。フロントにログイン導線。
3. **レビュー投稿UI**：ログインユーザーが `field_resource`＋2軸スコアで POST（バックエンドはQ1で実証済み）。
4. **コレクション Flag UI**：`owned`/`want`/`favorite` の付け外し（Q3で実証済み）。
5. **Search API ファセット**：件数バッジ等が要るフェーズで JSON:API filter から移行検討
   （`search_api`/`facets` は enable 済み）。

## 既知の再現性ギャップ（恒久化の候補）

- OAuth 秘密鍵が `/opt/drupal/keys`（コンテナローカル・要 `www-data` 所有）でコンテナ再構築時に消える。
  → keys 用ボリュームを `docker-compose.yml` に追加するのが望ましい。
- 設定変更（`jsonapi.settings` 等）が未 `drush cex`。config同期先を整えてリポジトリに反映したい。
