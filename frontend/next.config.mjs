/** @type {import('next').NextConfig} */
const nextConfig = {
  // ヘッドレス Drupal はサーバーサイド fetch で叩くため特別な設定は不要。
  // CodeSandbox 等のプロキシ配下プレビュードメインからの dev リクエストを許可
  // （dev 専用。本番/ローカルには影響なし）。
  allowedDevOrigins: ['*.csb.app', '*.codesandbox.io'],
};

export default nextConfig;
