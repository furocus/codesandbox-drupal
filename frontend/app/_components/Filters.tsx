'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import type { Term } from '@/lib/jsonapi';

type Props = {
  techTerms: Term[];
  audTerms: Term[];
  current: { type: string; tech: string; aud: string; q: string };
};

/**
 * 検索フィルタ UI（クライアント）。変更を URL searchParams に反映し、
 * サーバーコンポーネント(page.tsx)が再取得して一覧を更新する。
 */
export default function Filters({ techTerms, audTerms, current }: Props) {
  const router = useRouter();
  const [q, setQ] = useState(current.q);

  function pushWith(patch: Partial<typeof current>) {
    const next = { ...current, q, ...patch };
    const params = new URLSearchParams();
    if (next.type) params.set('type', next.type);
    if (next.tech) params.set('tech', next.tech);
    if (next.aud) params.set('aud', next.aud);
    if (next.q) params.set('q', next.q);
    const qs = params.toString();
    router.push(qs ? `/?${qs}` : '/');
  }

  return (
    <form
      className="filters"
      onSubmit={(e) => {
        e.preventDefault();
        pushWith({});
      }}
    >
      <div className="field">
        <label htmlFor="f-type">種別</label>
        <select
          id="f-type"
          value={current.type}
          onChange={(e) => pushWith({ type: e.target.value })}
        >
          <option value="">すべて</option>
          <option value="book">技術書籍</option>
          <option value="article">技術記事</option>
        </select>
      </div>

      <div className="field">
        <label htmlFor="f-tech">技術スタック</label>
        <select
          id="f-tech"
          value={current.tech}
          onChange={(e) => pushWith({ tech: e.target.value })}
        >
          <option value="">すべて</option>
          {techTerms.map((t) => (
            <option key={t.tid} value={t.tid}>
              {t.name}
            </option>
          ))}
        </select>
      </div>

      <div className="field">
        <label htmlFor="f-aud">対象者</label>
        <select id="f-aud" value={current.aud} onChange={(e) => pushWith({ aud: e.target.value })}>
          <option value="">すべて</option>
          {audTerms.map((t) => (
            <option key={t.tid} value={t.tid}>
              {t.name}
            </option>
          ))}
        </select>
      </div>

      <div className="field">
        <label htmlFor="f-q">キーワード（タイトル）</label>
        <input
          id="f-q"
          type="search"
          value={q}
          placeholder="例: Rust"
          onChange={(e) => setQ(e.target.value)}
        />
      </div>

      <button type="submit" className="reset" style={{ fontWeight: 600 }}>
        検索
      </button>
      <a className="reset" href="/">
        クリア
      </a>
    </form>
  );
}
