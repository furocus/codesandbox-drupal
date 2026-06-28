import type { Metadata } from 'next';
import './globals.css';

export const metadata: Metadata = {
  title: '技術リソース検索 — ヘッドレス Drupal MVP',
  description: '技術書・記事を tech_stack / 対象者 / キーワードで検索する一覧（JSON:API）',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="ja">
      <body>{children}</body>
    </html>
  );
}
